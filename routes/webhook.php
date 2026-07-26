<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;
use Uzbek\Wlcm\Webhooks\WebhookController;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

// URI and normalisation both come from WebhookRoute, which is the SAME source the
// provider's conflict watch reads. Two copies of the trim-and-fallback rule would drift,
// and the watch has to derive the URI from config to stay sighted under route:cache.
$route = Route::post(
    WebhookRoute::uri(config('wlcm.webhook.path')),
    WebhookController::class,
)->middleware(VerifyWebhookSignature::class)->name(WebhookRoute::NAME);

$connections = array_keys((array) config('wlcm.connections', []));

// Empty whereIn compiles to empty requirement, breaking routing app-wide.
if ($connections !== []) {
    $route->whereIn('connection', array_map(
        static fn (string|int $connection): string => preg_quote((string) $connection, '/'),
        $connections,
    ));
}
