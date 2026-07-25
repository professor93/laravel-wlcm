<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;
use Uzbek\Wlcm\Webhooks\WebhookController;

$path = config('wlcm.webhook.path');

// Array config kills BootProviders on the string cast, and a slash-only one registers a root catch-all.
$path = is_string($path) && rtrim($path, '/') !== '' ? rtrim($path, '/') : 'wlcm/webhook';

$route = Route::post(
    $path.'/{connection?}',
    WebhookController::class,
)->middleware(VerifyWebhookSignature::class)->name('wlcm.webhook');

$connections = array_keys((array) config('wlcm.connections', []));

// An empty whereIn compiles to an empty requirement, which breaks routing app-wide.
if ($connections !== []) {
    $route->whereIn('connection', array_map(
        static fn (string|int $connection): string => preg_quote((string) $connection, '/'),
        $connections,
    ));
}
