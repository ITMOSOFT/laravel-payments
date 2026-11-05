<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('itmosoft-payments.table_prefix', 'itmosoft_');

        Schema::create($prefix.'payments', function (Blueprint $table) {
            $table->id();
            $table->string('payable_type')->nullable();
            $table->unsignedBigInteger('payable_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->string('reference')->unique();
            $table->string('gateway')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('gateway_reference')->nullable()->index();
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->decimal('authorized_amount', 14, 2)->default(0);
            $table->decimal('captured_amount', 14, 2)->default(0);
            $table->decimal('refunded_amount', 14, 2)->default(0);
            $table->string('status', 32)->index();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['payable_type', 'payable_id']);
        });

        Schema::create($prefix.'payment_transactions', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('payment_id')->constrained($prefix.'payments')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('gateway_reference')->nullable()->index();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create($prefix.'payment_webhook_events', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('payment_id')->constrained($prefix.'payments')->cascadeOnDelete();
            $table->string('gateway')->nullable();
            $table->string('event_id');
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        $prefix = config('itmosoft-payments.table_prefix', 'itmosoft_');
        Schema::dropIfExists($prefix.'payment_webhook_events');
        Schema::dropIfExists($prefix.'payment_transactions');
        Schema::dropIfExists($prefix.'payments');
    }
};