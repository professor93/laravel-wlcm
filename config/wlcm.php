<?php

declare(strict_types=1);

// A published copy of this file is merged recursively under these defaults: keys
// you keep win, keys you drop fall back to the packaged value. Deleting a block
// here therefore cannot remove it — a connection you delete comes back.
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

    // Refuse to claim a webhook delivery, or to create a checkout, while the caller holds
    // an open database transaction. Both write a wlcm_transactions row that rolls back
    // with the caller while the payment and the events it triggered cannot. true accepts
    // that risk and restores the old behaviour. Only consulted when
    // storage.transactions is on — with no row to lose there is nothing to guard.
    'allow_caller_transaction' => false,

    'webhook' => [
        'enabled' => true,
        'path' => 'wlcm/webhook',
        // Host constraint for the webhook route. null serves it on every host.
        'domain' => env('WLCM_WEBHOOK_DOMAIN'),
        // Refuse deliveries from outside this list, BEFORE signature verification.
        // null or empty is off. CSV because an env value is always a string; a config
        // file may use an array instead. Entries may be an exact address, a CIDR block
        // or an inclusive start-end range, v4 or v6. A malformed entry throws rather
        // than silently never matching.
        //
        // PROXY TRAP: matching uses $request->ip(), which is your load balancer unless
        // the app configures TrustProxies. Behind a proxy without it, nothing WLCM sends
        // can ever match. See ADVANCED.md.
        'allowed_ips' => env('WLCM_WEBHOOK_ALLOWED_IPS'),
        'handler' => null,
        'subscription_handler' => null,
        // Reject signed deliveries whose timestamp is further than this from now.
        // null is the only disable; non-numeric or non-positive throws.
        'tolerance_seconds' => 300,
        // How long a claim counts as in flight. Only a hard-killed worker waits it out:
        // a handler that throws releases the claim on the spot. Set it above your
        // slowest handler — a handler that outruns it can be dispatched twice. Unlike
        // tolerance_seconds there is no null disable; null, non-numeric or non-positive
        // throws MissingConfigurationException on the first delivery.
        'lease_seconds' => 60,

        // When a WLCM delivery record counts as needing attention. Shared by
        // `wlcm:webhook:events --failed` and the scaffolded listeners, so the CLI and
        // your code cannot disagree. Any rule set to null is switched off.
        //
        // These are several INDEPENDENT signals on purpose. Across 59 real sandbox
        // deliveries every record read 'failed' — no successful one has ever been
        // observed — so 'delivered' is a guess, and a predicate resting on it alone
        // would pass everything the day the real vocabulary turned out to differ.
        // has_error_message is the rule that survives that.
        'alerts' => [
            'status_not_in' => ['delivered'],
            'has_error_message' => true,
            'response_code_not_in' => [200, 201, 202, 204],
            'attempts_gte' => 2,
            'stale_retry' => true,
        ],
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

        // 'file' driver only. Without these the JSONL grows until the disk does not —
        // prune_days covers the two TABLES and nothing else.
        'file' => [
            // Rotate once the file reaches this. '10MB', '512K' or a byte count;
            // null switches size rotation off.
            'max_size' => null,
            // One file per day, dated in the NAME (wlcm-2026-08-01.jsonl) so two
            // processes crossing midnight cannot disagree about whose turn it is.
            // Named in UTC, not APP_TIMEZONE.
            'daily' => false,
            // Rotated archives to keep, newest first. null keeps every one.
            'max_files' => null,
        ],
    ],
];
