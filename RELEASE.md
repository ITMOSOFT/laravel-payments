# GitHub Release: v1.0.0 — Initial Release

**Repository:** `ITMOSOFT/laravel-payments`

**Description:** Gateway-agnostic payment intents, transactions and webhook event tracking for Laravel applications.

## Included
- Payment records and polymorphic payable references.
- Authorization, capture, failure, cancellation and refund records.
- Transaction history and external gateway references.
- Webhook event storage with gateway/event idempotency.
- Gateway adapter contract and normalized result DTO.
- Payment lifecycle events.
- Configurable table prefix.
- PHPUnit tests and GitHub Actions workflow.

## Compatibility
- PHP 8.3+
- Laravel 12 and 13

## Scope exclusions
No gateway HTTP clients, webhook signature verification, actual money movement, or distributed locking is included.
