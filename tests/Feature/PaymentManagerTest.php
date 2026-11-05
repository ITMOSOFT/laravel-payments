<?php

namespace ItmoSoft\Payments\Tests\Feature;

use ItmoSoft\Payments\Exceptions\InvalidPaymentState;
use ItmoSoft\Payments\Services\PaymentManager;
use ItmoSoft\Payments\Tests\TestCase;

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