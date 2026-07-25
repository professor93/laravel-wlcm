<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;
use Uzbek\Wlcm\Webhooks\WebhookController;

$path = config('wlcm.webhook.path');

// Array config kills BootProviders on string cast; slash-or-whitespace-only registers root catch-all.
$path = is_string($path) ? trim($path, " \t\n\r\0\x0B/") : '';
$path = $path !== '' ? $path : 'wlcm/webhook';

$route = Route::post(
    $path.'/{connection?}',
    WebhookController::class,
)->middleware(VerifyWebhookSignature::class)->name('wlcm.webhook');

$connections = array_keys((array) config('wlcm.connections', []));

// Empty whereIn compiles to empty requirement, breaking routing app-wide.
if ($connections !== []) {
    $route->whereIn('connection', array_map(
        static fn (string|int $connection): string => preg_quote((string) $connection, '/'),
        $connections,
    ));
}
