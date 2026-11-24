<?php

namespace ItmoSoft\Payments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ItmoSoft\Payments\Events\PaymentAuthorized;
use ItmoSoft\Payments\Events\PaymentCaptured;
use ItmoSoft\Payments\Events\PaymentFailed;
use ItmoSoft\Payments\Events\PaymentRefundUpdated;
use ItmoSoft\Payments\Exceptions\InvalidPaymentState;
use ItmoSoft\Payments\Models\Payment;
use ItmoSoft\Payments\Models\PaymentTransaction;
use ItmoSoft\Payments\Models\PaymentWebhookEvent;
use InvalidArgumentException;

class PaymentManager
{
    public function create(array $data): Payment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }
        return Payment::query()->create([
            'payable_type' => $data['payable_type'] ?? null, 'payable_id' => $data['payable_id'] ?? null,
            'order_id' => $data['order_id'] ?? null, 'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? 'PAY-'.Str::upper(Str::random(12)),
            'gateway' => $data['gateway'] ?? null, 'payment_method' => $data['payment_method'] ?? null,
            'gateway_reference' => $data['gateway_reference'] ?? null,
            'currency' => strtoupper($data['currency'] ?? config('itmosoft-payments.default_currency', 'USD')),
            'amount' => $amount, 'authorized_amount' => 0, 'captured_amount' => 0,
            'refunded_amount' => 0, 'status' => 'pending', 'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function authorize(Payment $payment, array $result = []): Payment
    {
        $updated = DB::transaction(function () use ($payment, $result): Payment {
            $locked = $this->lock($payment);
            $this->assertStatus($locked, ['pending'], 'authorize');
            $amount = round((float) ($result['amount'] ?? $locked->amount), 2);
            if ($amount <= 0 || $amount > (float) $locked->amount) {
                throw new InvalidArgumentException('Authorized amount must be greater than zero and no greater than payment amount.');
            }
            $locked->forceFill(['status' => 'authorized', 'authorized_amount' => $amount,
                'gateway_reference' => $result['gateway_reference'] ?? $locked->gateway_reference, 'authorized_at' => now()])->save();
            $this->transaction($locked, 'authorization', $amount, $result);
            return $locked->refresh()->load('transactions');
        });
        event(new PaymentAuthorized($updated));
        return $updated;
    }

    public function capture(Payment $payment, array $result = []): Payment
    {
        $updated = DB::transaction(function () use ($payment, $result): Payment {
            $locked = $this->lock($payment);
            $this->assertStatus($locked, ['authorized', 'pending'], 'capture');
            $remaining = round((float) $locked->amount - (float) $locked->captured_amount, 2);
            $default = $locked->status === 'authorized' ? round((float) $locked->authorized_amount - (float) $locked->captured_amount, 2) : $remaining;
            $amount = round((float) ($result['amount'] ?? $default), 2);
            $captureLimit = $locked->status === 'authorized' ? $default : $remaining;
            if ($amount <= 0 || $amount > $captureLimit) {
                throw new InvalidArgumentException('Captured amount must be positive and cannot exceed the remaining authorized amount.');
            }
            $captured = round((float) $locked->captured_amount + $amount, 2);
            $locked->forceFill(['captured_amount' => $captured,
                // A direct capture from pending implicitly authorizes the full payment amount.
                'authorized_amount' => $locked->status === 'pending' ? $locked->amount : $locked->authorized_amount,
                'status' => $captured >= (float) $locked->amount ? 'captured' : 'authorized',
                'gateway_reference' => $result['gateway_reference'] ?? $locked->gateway_reference, 'captured_at' => now()])->save();
            $this->transaction($locked, 'capture', $amount, $result);
            return $locked->refresh()->load('transactions');
        });
        event(new PaymentCaptured($updated));
        return $updated;
    }

    public function fail(Payment $payment, array $result = []): Payment
    {
        $updated = DB::transaction(function () use ($payment, $result): Payment {
            $locked = $this->lock($payment);
            $this->assertStatus($locked, ['pending', 'authorized'], 'fail');
            $locked->forceFill(['status' => 'failed'])->save();
            $this->transaction($locked, 'failure', 0, $result);
            return $locked->refresh()->load('transactions');
        });
        event(new PaymentFailed($updated));
        return $updated;
    }

    public function cancel(Payment $payment, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            $locked = $this->lock($payment);
            $this->assertStatus($locked, ['pending', 'authorized'], 'cancel');
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            $this->transaction($locked, 'cancellation', 0, ['message' => $reason]);
            return $locked->refresh()->load('transactions');
        });
    }

    public function recordRefund(Payment $payment, array $data): Payment
    {
        $updated = DB::transaction(function () use ($payment, $data): Payment {
            $locked = $this->lock($payment);
            $amount = round((float) ($data['amount'] ?? 0), 2);
            $available = round((float) $locked->captured_amount - (float) $locked->refunded_amount, 2);
            if ((float) $locked->captured_amount <= 0 || $amount <= 0 || $amount > $available) {
                throw new InvalidArgumentException('Refund amount must be positive and cannot exceed the captured, unrefunded amount.');
            }
            $refunded = round((float) $locked->refunded_amount + $amount, 2);
            $locked->forceFill(['refunded_amount' => $refunded,
                'status' => $refunded >= (float) $locked->captured_amount ? 'refunded' : 'partially_refunded'])->save();
            $this->transaction($locked, 'refund', $amount, $data);
            return $locked->refresh()->load('transactions');
        });
        event(new PaymentRefundUpdated($updated));
        return $updated;
    }

    public function recordWebhook(Payment $payment, array $data): PaymentWebhookEvent
    {
        if (empty($data['event_id']) || empty($data['event_type'])) {
            throw new InvalidArgumentException('Webhook event_id and event_type are required.');
        }
        return PaymentWebhookEvent::query()->firstOrCreate(
            ['gateway' => $data['gateway'] ?? $payment->gateway, 'event_id' => $data['event_id']],
            ['payment_id' => $payment->id, 'event_type' => $data['event_type'], 'payload' => $data['payload'] ?? [], 'processed_at' => null]
        );
    }

    public function markWebhookProcessed(PaymentWebhookEvent $event): PaymentWebhookEvent
    {
        return DB::transaction(function () use ($event): PaymentWebhookEvent {
            $locked = PaymentWebhookEvent::query()->lockForUpdate()->findOrFail($event->getKey());
            if ($locked->processed_at === null) {
                $locked->processed_at = now();
                $locked->save();
            }
            return $locked->refresh();
        });
    }

    private function lock(Payment $payment): Payment
    {
        return Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
    }

    private function assertStatus(Payment $payment, array $allowed, string $operation): void
    {
        if (! in_array($payment->status, $allowed, true)) {
            throw new InvalidPaymentState("Cannot {$operation} a payment in [{$payment->status}] state.");
        }
    }

    private function transaction(Payment $payment, string $type, float $amount, array $data): void
    {
        PaymentTransaction::query()->create([
            'payment_id' => $payment->id, 'type' => $type, 'status' => $data['status'] ?? 'recorded',
            'amount' => $amount, 'gateway_reference' => $data['gateway_reference'] ?? null,
            'message' => $data['message'] ?? null, 'payload' => $data['payload'] ?? $data, 'occurred_at' => now(),
        ]);
    }
}
