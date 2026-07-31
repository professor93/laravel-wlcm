<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Uzbek\Wlcm\Webhooks\AllowWebhookIps;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;
use Uzbek\Wlcm\Webhooks\WebhookController;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

// URI and normalisation both come from WebhookRoute, which is the SAME source the
// provider's conflict watch reads. Two copies of the trim-and-fallback rule would drift,
// and the watch has to derive the URI from config because a baked table can hold no route
// of ours to read it from. When the watch RUNS is a separate half of that, in the provider.
$route = Route::post(
    WebhookRoute::uri(config('wlcm.webhook.path')),
    WebhookController::class,
    // Allowlist BEFORE signature: an address we do not serve should never reach the
    // constant-time secret work, and the cheaper check belongs first.
)->middleware([AllowWebhookIps::class, VerifyWebhookSignature::class])->name(WebhookRoute::NAME);

// Same trim-and-fallback rule as the path: a whitespace-only domain is not a domain, and
// registering one as a host constraint would match nothing and take the endpoint offline.
$domain = WebhookRoute::domain(config('wlcm.webhook.domain'));

if ($domain !== null) {
    $route->domain($domain);
}

$connections = array_keys((array) config('wlcm.connections', []));

// Empty whereIn compiles to empty requirement, breaking routing app-wide.
if ($connections !== []) {
    $route->whereIn('connection', array_map(
        static fn (string|int $connection): string => preg_quote((string) $connection, '/'),
        $connections,
    ));
}
