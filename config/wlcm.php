<?php

declare(strict_types=1);

return [
    'default' => env('WLCM_CONNECTION', 'main'),

    'connections' => [
        'main' => [
            'partner' => [
                'base_url' => env('WLCM_BASE_URL'),
                'api_key' => env('WLCM_API_KEY'),
                'api_secret' => env('WLCM_API_SECRET'),
                'webhook_secret' => env('WLCM_WEBHOOK_SECRET'),
                // Server-native unit for FIXED split amounts. 'sum' converts your
                // tiyin input via Tiyin::toSum; 'tiyin' passes it through raw.
                // Sandbox-proven SUM (server compares fixed totals to the payment
                // SUM value); docs claim tiyin. Pending final WLCM confirmation
                // 2026-07-25 — flip to 'tiyin' only if WLCM confirms the docs.
                'split_amount_unit' => env('WLCM_SPLIT_AMOUNT_UNIT', 'sum'),
            ],
            'http' => [
                'timeout' => 15,
                'connect_timeout' => 5,
                'get_retries' => 2,
            ],
        ],
    ],

    'webhook' => [
        'enabled' => true,
        'path' => 'wlcm/webhook',
        'handler' => null,
        'subscription_handler' => null,
    ],

    'storage' => [
        'logs' => false,
        'transactions' => false,
        'prune_days' => 90,
    ],

    'cache' => [
        'store' => null,
        'prefix' => 'wlcm',
    ],
];
