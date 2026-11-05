<?php

namespace ItmoSoft\Payments\Events;

use ItmoSoft\Payments\Models\Payment;

class PaymentFailed
{
    public function __construct(public readonly Payment $payment) {}
}