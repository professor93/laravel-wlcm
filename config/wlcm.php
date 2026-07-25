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
                // Server-native unit for FIXED split amounts. 'sum' converts your tiyin
                // input via Tiyin::toSum; 'tiyin' passes it raw. Sandbox-proven SUM
                // (server compares fixed totals to payment SUM value); docs claim tiyin.
                // Pending final WLCM confirmation 2026-07-25 — flip to 'tiyin' only if
                // WLCM confirms the docs.
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
        // Reject signed deliveries whose timestamp is further than this from now.
        // null is the only disable; non-numeric or non-positive throws.
        'tolerance_seconds' => 300,
    ],

    'storage' => [
        // 'database' (wlcm_logs table), 'file' (JSON lines), 'log' (Laravel log channel).
        // true/'1' = database; false/'0'/null/'' = off; unknown throws on first resolve.
        'logs' => env('WLCM_LOGS', false),
        // 'log' driver only; null = app's default channel.
        'logs_channel' => env('WLCM_LOGS_CHANNEL'),
        // 'file' driver only; null = storage_path('logs/wlcm.jsonl').
        'logs_path' => env('WLCM_LOGS_PATH'),
        'transactions' => false,
        // null or garbage disables pruning; days clamped to >= 1.
        'prune_days' => 90,
        // Reaps abandoned checkouts (no webhook ever arrived); null = keep forever.
        'stale_days' => null,
    ],
];
