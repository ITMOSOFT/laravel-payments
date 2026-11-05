<?php

namespace ItmoSoft\Payments\Contracts;

final class GatewayResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly bool $successful,
        public readonly string $status,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {}
}