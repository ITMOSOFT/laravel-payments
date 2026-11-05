<?php

namespace ItmoSoft\Payments\Events;

use ItmoSoft\Payments\Models\Payment;

class PaymentCaptured
{
    public function __construct(public readonly Payment $payment) {}
}