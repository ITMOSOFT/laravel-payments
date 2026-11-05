# ITMOSOFT Laravel Payments

Gateway-agnostic payment intents, transactions and webhook event tracking for Laravel 12 and 13.

## Features

- Payment records linked to orders, invoices or polymorphic payable models.
- Authorization, capture, cancellation, failure and refund records.
- Multiple transaction attempts per payment.
- External gateway and transaction references.
- Partial and full refund amount tracking.
- Webhook event persistence with gateway/event idempotency.
- Gateway contract and normalized result DTO.
- Events for authorization, capture, failure and refund updates.
- Configurable table prefix.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require itmosoft/laravel-payments
php artisan vendor:publish --tag=itmosoft-payments-config
php artisan migrate
```

## Usage

```php
use ItmoSoft\Payments\Facades\Payments;

$payment = Payments::create([
    'order_id' => 123,
    'amount' => 79.95,
    'currency' => 'USD',
    'gateway' => 'stripe',
    'payment_method' => 'card',
]);

$payment = Payments::authorize($payment, [
    'gateway_reference' => 'pi_external_123',
]);

$payment = Payments::capture($payment);
$payment = Payments::recordRefund($payment, ['amount' => 10]);
```

The manager records gateway outcomes; it does not make HTTP calls. Implement `ItmoSoft\Payments\Contracts\PaymentGateway` in a gateway adapter and call the manager after receiving a verified gateway result.

## Webhooks

```php
$event = Payments::recordWebhook($payment, [
    'gateway' => 'stripe',
    'event_id' => 'evt_external_123',
    'event_type' => 'payment_intent.succeeded',
    'payload' => $verifiedPayload,
]);

// Process the verified event, then mark it processed.
Payments::markWebhookProcessed($event);
```

The unique `(gateway, event_id)` key and `firstOrCreate` prevent duplicate event rows. Verify webhook signatures in your gateway adapter before recording or acting on events.

## Status behavior

- `pending` -> `authorized`, `captured`, `failed`, or `cancelled`
- `authorized` -> `captured`, `failed`, or `cancelled`
- `captured` -> `partially_refunded` or `refunded`
- `partially_refunded` -> `partially_refunded` or `refunded`
- `failed`, `cancelled`, and `refunded` are terminal through the manager API

`capture()` from `pending` records a direct capture. Refund records cannot exceed the captured amount less prior refunds.

## Tests

```bash
composer install
composer test
```

## Boundaries and production notes

- No gateway API calls or webhook signature verification are performed by the core package.
- A recorded refund is a bookkeeping update, not a money movement. Execute the refund with a trusted gateway adapter first.
- This initial version does not provide distributed locking or a complete concurrency-control strategy for simultaneous capture/refund calls. Add application-level locking or a gateway idempotency strategy for high-volume production use.
- Do not trust client-supplied payment status or webhook payloads. Only record outcomes from verified server-side gateway responses.

## License

MIT.
