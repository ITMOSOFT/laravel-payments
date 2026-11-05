<?php

namespace ItmoSoft\Payments;

use Illuminate\Support\ServiceProvider;
use ItmoSoft\Payments\Services\PaymentManager;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payments.php', 'itmosoft-payments');
        $this->app->singleton(PaymentManager::class);
        $this->app->alias(PaymentManager::class, 'itmosoft.payments');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/payments.php' => config_path('itmosoft-payments.php')], 'itmosoft-payments-config');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}