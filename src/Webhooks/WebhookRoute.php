<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * One definition of where the webhook route lives, and one definition of what counts as
 * something else having taken it.
 *
 * Both halves are shared with `routes/webhook.php` on purpose. The conflict watch used to
 * learn the URI by finding our OWN registered route, which made it blind in the single
 * configuration it most needed to see: with `route:cache`, a table baked while a host
 * route already shadowed ours contains no route named `wlcm.webhook`, so the lookup found
 * nothing and no watch installed. The state the guard exists to catch was the state it
 * could not see — registration-time knowledge defeated by a baked table.
 *
 * Deriving the URI from config instead fixes that, and the objection to doing so was
 * sound: it would mean a second copy of the trim-and-fallback normalisation, and two
 * copies drift. So there is exactly one copy, here, and the route file calls it.
 */
final class WebhookRoute
{
    public const string NAME = 'wlcm.webhook';

    private const string DEFAULT_PATH = 'wlcm/webhook';

    // Optional, so one route serves both `/wlcm/webhook` and `/wlcm/webhook/{connection}`.
    private const string CONNECTION_SEGMENT = '/{connection?}';

    /**
     * An array config kills BootProviders on string cast; a slash-or-whitespace-only value
     * would register a root catch-all. Both collapse to the default instead.
     */
    public static function path(mixed $configured): string
    {
        $path = is_string($configured) ? trim($configured, " \t\n\r\0\x0B/") : '';

        return $path !== '' ? $path : self::DEFAULT_PATH;
    }

    public static function uri(mixed $configured): string
    {
        return self::path($configured).self::CONNECTION_SEGMENT;
    }

    /**
     * The route serving $uri when it is NOT carrying signature verification — i.e. the one
     * that has silently taken our endpoint and will hand merchant code an unverified body.
     *
     * Keyed on the middleware, never on whose route it is: a host that re-registers the
     * path and reattaches VerifyWebhookSignature has done something deliberate and safe,
     * and must not be reported.
     *
     * Null when nothing serves the URI at all. That stays silent deliberately — with
     * routes uncached we have just registered it ourselves, so null means either the
     * endpoint is switched off (the caller does not watch in that case) or a cached table
     * was built for a different `wlcm.webhook.path`, which is a stale-cache problem
     * documented with the other deploy-cache hazards rather than a shadowing one.
     */
    public static function shadow(Router $router, string $uri): ?Route
    {
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getDomain() !== null
                || $route->uri() !== $uri
                || ! in_array('POST', $route->methods(), true)) {
                continue;
            }

            return in_array(VerifyWebhookSignature::class, (array) $route->middleware(), true)
                ? null
                : $route;
        }

        return null;
    }
}
