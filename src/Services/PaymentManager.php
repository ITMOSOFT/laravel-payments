<?php

namespace ItmoSoft\Payments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ItmoSoft\Payments\Events\PaymentAuthorized;
use ItmoSoft\Payments\Events\PaymentCaptured;
use ItmoSoft\Payments\Events\PaymentFailed;
use ItmoSoft\Payments\Events\PaymentRefundUpdated;
use ItmoSoft\Payments\Exceptions\InvalidPaymentState;
use ItmoSoft\Payments\Models\Payment;
use ItmoSoft\Payments\Models\PaymentTransaction;
use ItmoSoft\Payments\Models\PaymentWebhookEvent;

class PaymentManager
{
    public function create(array $data): Payment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return Payment::query()->create([
            'payable_type' => $data['payable_type'] ?? null,
            'payable_id' => $data['payable_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'reference' => $data['reference'] ?? 'PAY-'.Str::upper(Str::random(12)),
            'gateway' => $data['gateway'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'gateway_reference' => $data['gateway_reference'] ?? null,
            'currency' => strtoupper($data['currency'] ?? config('itmosoft-payments.default_currency', 'USD')),
            'amount' => $amount,
            'authorized_amount' => 0,
            'captured_amount' => 0,
            'refunded_amount' => 0,
            'status' => 'pending',
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function authorize(Payment $payment, array $result = []): Payment
    {
        $this->assertStatus($payment, ['pending'], 'authorize');
        $amount = round((float) ($result['amount'] ?? $payment->amount), 2);
        if ($amount <= 0 || $amount > (float) $payment->amount) {
            throw new \InvalidArgumentException('Authorized amount must be greater than zero and no greater than payment amount.');
        }

        return DB::transaction(function () use ($payment, $result, $amount) {
            $payment->status = 'authorized';
            $payment->authorized_amount = $amount;
            $payment->gateway_reference = $result['gateway_reference'] ?? $payment->gateway_reference;
            $payment->authorized_at = now();
            $payment->save();
            $this->transaction($payment, 'authorization', $amount, $result);
            $payment->refresh()->load('transactions');
            Event::dispatch(new PaymentAuthorized($payment));
            return $payment;
        });
    }

    public function capture(Payment $payment, array $result = []): Payment
    {
        $this->assertStatus($payment, ['authorized', 'pending'], 'capture');
        $remaining = (float) $payment->amount - (float) $payment->captured_amount;
        $amount = round((float) ($result['amount'] ?? ($payment->status === 'authorized' ? $payment->authorized_amount : $remaining)), 2);
        if ($amount <= 0 || $amount > $remaining) {
            throw new \InvalidArgumentException('Captured amount must be greater than zero and cannot exceed the remaining payment amount.');
        }

        return DB::transaction(function () use ($payment, $result, $amount) {
            $payment->captured_amount = round((float) $payment->captured_amount + $amount, 2);
            $payment->status = $payment->captured_amount >= (float) $payment->amount ? 'captured' : 'authorized';
            $payment->gateway_reference = $result['gateway_reference'] ?? $payment->gateway_reference;
            $payment->captured_at = now();
            $payment->save();
            $this->transaction($payment, 'capture', $amount, $result);
            $payment->refresh()->load('transactions');
            Event::dispatch(new PaymentCaptured($payment));
            return $payment;
        });
    }

    public function fail(Payment $payment, array $result = []): Payment
    {
        $this->assertStatus($payment, ['pending', 'authorized'], 'fail');
        $payment->status = 'failed';
        $payment->save();
        $this->transaction($payment, 'failure', 0, $result);
        $payment->refresh()->load('transactions');
        Event::dispatch(new PaymentFailed($payment));
        return $payment;
    }

    public function cancel(Payment $payment, ?string $reason = null): Payment
    {
        $this->assertStatus($payment, ['pending', 'authorized'], 'cancel');
        $payment->status = 'cancelled';
        $payment->cancelled_at = now();
        $payment->save();
        $this->transaction($payment, 'cancellation', 0, ['message' => $reason]);
        return $payment->refresh()->load('transactions');
    }

    public function recordRefund(Payment $payment, array $data): Payment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $available = round((float) $payment->captured_amount - (float) $payment->refunded_amount, 2);
        if ($payment->captured_amount <= 0 || $amount <= 0 || $amount > $available) {
            throw new \InvalidArgumentException('Refund amount must be positive and cannot exceed the captured, unrefunded amount.');
        }

        return DB::transaction(function () use ($payment, $data, $amount) {
            $payment->refunded_amount = round((float) $payment->refunded_amount + $amount, 2);
            $payment->status = $payment->refunded_amount >= (float) $payment->captured_amount
                ? 'refunded' : 'partially_refunded';
            $payment->save();
            $this->transaction($payment, 'refund', $amount, $data);
            $payment->refresh()->load('transactions');
            Event::dispatch(new PaymentRefundUpdated($payment));
            return $payment;
        });
    }

    public function recordWebhook(Payment $payment, array $data): PaymentWebhookEvent
    {
        if (empty($data['event_id']) || empty($data['event_type'])) {
            throw new \InvalidArgumentException('Webhook event_id and event_type are required.');
        }

        return PaymentWebhookEvent::query()->firstOrCreate(
            ['gateway' => $data['gateway'] ?? $payment->gateway, 'event_id' => $data['event_id']],
            [
                'payment_id' => $payment->id,
                'event_type' => $data['event_type'],
                'payload' => $data['payload'] ?? [],
                'processed_at' => null,
            ]
        );
    }

    public function markWebhookProcessed(PaymentWebhookEvent $event): PaymentWebhookEvent
    {
        if (!$event->processed_at) {
            $event->processed_at = now();
            $event->save();
        }
        return $event;
    }

    private function assertStatus(Payment $payment, array $allowed, string $operation): void
    {
        if (!in_array($payment->status, $allowed, true)) {
            throw new InvalidPaymentState("Cannot {$operation} a payment in [{$payment->status}] state.");
        }
    }

    private function transaction(Payment $payment, string $type, float $amount, array $data): void
    {
        PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'type' => $type,
            'status' => $data['status'] ?? 'recorded',
            'amount' => $amount,
            'gateway_reference' => $data['gateway_reference'] ?? null,
            'message' => $data['message'] ?? null,
            'payload' => $data['payload'] ?? $data,
            'occurred_at' => now(),
        ]);
    }
}