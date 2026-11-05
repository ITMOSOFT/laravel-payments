<?php

namespace ItmoSoft\Payments\Facades;

use Illuminate\Support\Facades\Facade;

class Payments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'itmosoft.payments';
    }
}