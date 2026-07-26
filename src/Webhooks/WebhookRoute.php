<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Illuminate\Http\Request;
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
     * The route that would actually serve a POST to our endpoint when it is NOT carrying
     * signature verification — i.e. the one that has silently taken the endpoint and will
     * hand merchant code an unverified body.
     *
     * ASK THE ROUTER THE QUESTION THE ROUTER ASKS. The previous version scanned
     * `getRoutes()` for a textual URI match, and that was wrong twice over, because
     * `RouteCollection` maintains two tables with DIFFERENT keys:
     *
     *   $allRoutes[implode('|', $methods).$domainAndUri]   // what getRoutes() walks
     *   $routes[$method][$domainAndUri]                    // what matching walks
     *
     * They disagree for any route whose method list is not exactly ['POST']. A host
     * `Route::any()` evicts ours from `routes['POST'][$uri]` — so it serves the endpoint —
     * yet lands under a different `allRoutes` key, leaving OUR route alive there as a
     * phantom. The old scan met the phantom first, saw our middleware and returned null.
     * Measured over eight host shapes x both registration orders: 7 silent misses
     * (`any`, `match`, `redirect`, `permanentRedirect`, a domained post, a param wildcard)
     * and 4 false alarms, with only 5 of 16 cells correct. `Route::redirect()` is not
     * exotic — it is literally `any($uri, RedirectController::class)`, so a merchant
     * redirecting a moved webhook path hit the silent case.
     *
     * So: walk `get('POST')`, which is the same table dispatch matches against — it
     * excludes phantoms, and returns domained routes first exactly as `matchAgainstRoutes`
     * sees them — and take the FIRST route that matches, because first match wins. Use
     * `Route::matches()` rather than comparing URI strings, so a param wildcard that
     * captures our path is caught too; it compiles and validates without binding, so
     * calling it during boot has no effect on request handling.
     *
     * Null when nothing serves the endpoint at all. That stays silent deliberately: no
     * unverified body reaches anyone, WLCM gets 404s and fails loudly through failed
     * deliveries, so it is an availability problem with its own signal rather than a silent
     * security hole.
     */
    public static function shadow(Router $router, mixed $configuredPath, ?string $sampleConnection = null): ?Route
    {
        foreach (self::probes($configuredPath, $sampleConnection) as $request) {
            foreach ($router->getRoutes()->get('POST') as $route) {
                // Method already decided by the table we are walking.
                if (! $route->matches($request, false)) {
                    continue;
                }

                if (! self::verifies($route)) {
                    return $route;
                }

                break; // A verifying route wins this shape; try the next.
            }
        }

        // A domained route cannot be probed without knowing its host, but one sitting on
        // our URI does serve our endpoint for that host, unverified. Reported on identity.
        foreach ($router->getRoutes()->get('POST') as $route) {
            if ($route->getDomain() !== null
                && $route->uri() === self::uri($configuredPath)
                && ! self::verifies($route)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Both shapes our own route answers: with the optional connection segment absent, and
     * with it present. A host route whose parameter is REQUIRED
     * (`wlcm/webhook/{anything}`) only captures the second, so probing one form alone
     * misses it — which is how the param-wildcard shape stayed silent.
     *
     * @return list<Request>
     */
    private static function probes(mixed $configuredPath, ?string $sampleConnection): array
    {
        $path = '/'.self::path($configuredPath);

        $probes = [Request::create($path, 'POST')];

        if ($sampleConnection !== null && $sampleConnection !== '') {
            $probes[] = Request::create($path.'/'.$sampleConnection, 'POST');
        }

        return $probes;
    }

    // Route-level middleware only, which is where both we and a deliberate host put it.
    private static function verifies(Route $route): bool
    {
        return in_array(VerifyWebhookSignature::class, (array) $route->middleware(), true);
    }
}
