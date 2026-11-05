<?php

return [
    'table_prefix' => env('ITMOSOFT_PAYMENTS_TABLE_PREFIX', 'itmosoft_'),
    'default_currency' => env('ITMOSOFT_PAYMENTS_DEFAULT_CURRENCY', 'USD'),
    'statuses' => [
        'pending', 'authorized', 'captured', 'partially_refunded',
        'refunded', 'failed', 'cancelled',
    ],
];