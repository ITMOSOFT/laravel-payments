<?php

namespace ItmoSoft\Payments\Contracts;

interface PaymentGateway
{
    /** @param array<string, mixed> $payload */
    public function authorize(array $payload): GatewayResult;

    /** @param array<string, mixed> $payload */
    public function capture(array $payload): GatewayResult;

    /** @param array<string, mixed> $payload */
    public function cancel(array $payload): GatewayResult;

    /** @param array<string, mixed> $payload */
    public function refund(array $payload): GatewayResult;
}