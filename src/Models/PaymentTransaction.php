<?php

namespace ItmoSoft\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'payload' => 'array', 'occurred_at' => 'datetime'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function getTable(): string
    {
        return config('itmosoft-payments.table_prefix', 'itmosoft_').'payment_transactions';
    }
}