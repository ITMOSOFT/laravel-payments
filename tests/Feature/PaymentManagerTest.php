<?php

namespace ItmoSoft\Payments\Tests\Feature;

use ItmoSoft\Payments\Exceptions\InvalidPaymentState;
use ItmoSoft\Payments\Services\PaymentManager;
use ItmoSoft\Payments\Tests\TestCase;
use InvalidArgumentException;

class PaymentManagerTest extends TestCase
{
    public function test_it_creates_and_captures_payment(): void
    {
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['order_id' => 10, 'amount' => 40]);
        $this->assertSame('pending', $payment->status);
        $payment = $manager->authorize($payment);
        $this->assertSame('authorized', $payment->status);
        $payment = $manager->capture($payment);
        $this->assertSame('captured', $payment->status);
        $this->assertSame('40.00', $payment->captured_amount);
        $this->assertCount(2, $payment->transactions);
    }

    public function test_it_records_partial_and_full_refunds(): void
    {
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['order_id' => 11, 'amount' => 50]);
        $payment = $manager->capture($payment);
        $payment = $manager->recordRefund($payment, ['amount' => 15]);
        $this->assertSame('partially_refunded', $payment->status);
        $payment = $manager->recordRefund($payment, ['amount' => 35]);
        $this->assertSame('refunded', $payment->status);
    }

    public function test_it_rejects_refund_exceeding_remaining_captured_amount(): void
    {
        $manager = app(PaymentManager::class);
        $payment = $manager->capture($manager->create(['order_id' => 111, 'amount' => 50]));
        $payment = $manager->recordRefund($payment, ['amount' => 40]);
        $this->expectException(InvalidArgumentException::class);
        $manager->recordRefund($payment, ['amount' => 11]);
    }

    public function test_it_reloads_state_instead_of_trusting_a_stale_model_for_capture(): void
    {
        $manager = app(PaymentManager::class);
        $stale = $manager->create(['order_id' => 112, 'amount' => 25]);
        $manager->fail($stale->fresh());
        $this->expectException(InvalidPaymentState::class);
        $manager->capture($stale);
    }

    public function test_capture_cannot_exceed_the_authorized_amount(): void
    {
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['order_id' => 113, 'amount' => 50]);
        $payment = $manager->authorize($payment, ['amount' => 20]);
        $this->expectException(InvalidArgumentException::class);
        $manager->capture($payment, ['amount' => 21]);
    }

    public function test_it_rejects_capture_from_failed_state(): void
    {
        $this->expectException(InvalidPaymentState::class);
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['order_id' => 12, 'amount' => 20]);
        $payment = $manager->fail($payment);
        $manager->capture($payment);
    }

    public function test_webhook_events_are_idempotent_by_gateway_event_id(): void
    {
        $manager = app(PaymentManager::class);
        $payment = $manager->create(['order_id' => 13, 'amount' => 9, 'gateway' => 'demo']);
        $data = ['event_id' => 'evt_123', 'event_type' => 'payment.updated', 'gateway' => 'demo'];
        $first = $manager->recordWebhook($payment, $data);
        $second = $manager->recordWebhook($payment, $data);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $payment->webhookEvents()->count());
    }
}
