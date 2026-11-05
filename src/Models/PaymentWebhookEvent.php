<?php

namespace ItmoSoft\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function getTable(): string
    {
        return config('itmosoft-payments.table_prefix', 'itmosoft_').'payment_webhook_events';
    }
}