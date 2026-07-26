<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;

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
 * Deriving the URI from config instead removes that dependency, and the objection to doing
 * so was sound: it would mean a second copy of the trim-and-fallback normalisation, and two
 * copies drift. So there is exactly one copy, here, and the route file calls it.
 *
 * That was only HALF of the route:cache problem, and this comment used to claim the whole
 * of it. Knowing the URI is worth nothing if the watch runs before the cached table is
 * installed — which it did, so the guard was inert under the standard production deploy.
 * The other half is timing and it is fixed where it lives, in the provider.
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
     * A route that would serve a POST to our endpoint WITHOUT carrying signature
     * verification — i.e. one that has silently taken the endpoint and will hand merchant
     * code an unverified body.
     *
     * ASK THE ROUTER'S OWN QUESTION. An earlier version scanned `getRoutes()` for a textual
     * URI match, and that was wrong twice over, because `RouteCollection` maintains two
     * tables with DIFFERENT keys:
     *
     *   $allRoutes[implode('|', $methods).$domainAndUri]   // what getRoutes() walks
     *   $routes[$method][$domainAndUri]                    // what matching walks
     *
     * They disagree for any route whose method list is not exactly ['POST']. A host
     * `Route::any()` evicts ours from `routes['POST'][$uri]` — so it serves the endpoint —
     * yet lands under a different `allRoutes` key, leaving OUR route alive there as a
     * phantom that still carried our middleware. So this walks `get('POST')`, the same
     * table dispatch matches against, and decides capture with `Route::matches()` rather
     * than a URI string compare, which is what catches a parameter that merely captures our
     * path, a renamed or required parameter, and a bare path with no parameter at all.
     *
     * THE TABLE IS SPLIT BY DOMAIN, because the two halves answer different questions and
     * the previous version answered only the first one for both:
     *
     *   - A DOMAINLESS route answers on every host, so the order these appear in is the
     *     order dispatch tries them on any host. First capture wins; if it verifies, it is
     *     the deliberate way to front the endpoint and nothing is reported.
     *   - A DOMAINED route answers only on its own host, and which host WLCM delivers to is
     *     not knowable here. So the domain is not matched at all: any domained route that
     *     captures our path unverified is reported.
     *
     * The split is the one `RouteCollection::addToCollections()` makes, on TRUTHINESS rather
     * than null — `Route::domain('')`, which `domain(config(...))` produces from an unset
     * key, carries a domain that matches every host and lives in the domainless table.
     *
     * That second rule OVER-REPORTS on purpose — it fires for a domained route on a host
     * WLCM never posts to, and an `admin.example.com` catch-all cannot take the slot for
     * deliveries addressed to `api.example.com`. This is a `report()` rather than a refusal,
     * and a miss is the failure that matters; a false alarm is a five-second `route:list`.
     * The previous version tried to have it both ways — it probed with `Request::create()`,
     * whose default host is `localhost`, so `HostValidator` rejected every real domain, and
     * fell back to comparing `$route->uri()` to ours as strings. That string compare
     * included the PARAMETER'S NAME, so a domained `wlcm/webhook/{conn?}`, a required
     * `{connection}`, a bare `wlcm/webhook`, a `{any}` catch-all, a `wlcm/{rest}` and any
     * tenant-patterned domain all served unverified deliveries in silence, while the
     * identical route WITHOUT a domain was caught. Domained routes were strictly weaker,
     * and `RouteCollection::get($method)` returns them FIRST, so they outrank ours on their
     * host.
     *
     * Null when nothing serves the endpoint at all. That stays silent deliberately: no
     * unverified body reaches anyone, WLCM gets 404s and fails loudly through failed
     * deliveries, so it is an availability problem with its own signal rather than a silent
     * security hole.
     *
     * TWO RESIDUALS, both toward noise rather than silence. A route flagged
     * `->fallback()` is treated as any other, though dispatch tries fallbacks last — so a
     * hand-made POST fallback registered before ours can raise a false alarm
     * (`Route::fallback()` itself registers GET only, so it never reaches this table). And
     * two domained routes on the SAME host are not ordered against each other: a verifying
     * one first does not silence an unverified one behind it.
     *
     * @param  list<string>  $connections  Configured connection names.
     */
    public static function shadow(Router $router, mixed $configuredPath, array $connections = []): ?Route
    {
        $routes = [];
        $copies = [];

        foreach ($router->getRoutes()->get('POST') as $route) {
            $routes[] = $route;
            $copies[] = self::probeCopy($route);
        }

        $probes = self::probes($configuredPath, $connections);

        // Domainless: dispatch order on every host.
        foreach ($probes as $probe) {
            foreach ($routes as $i => $route) {
                if (self::isDomained($route) || ! $copies[$i]->matches($probe, false)) {
                    continue;
                }

                if (! self::verifies($route)) {
                    return $route;
                }

                break; // A verifying route wins this shape on every host; try the next.
            }
        }

        // Domained: host unknowable, so capture alone decides. No break — a verifying route
        // on one host says nothing about an unverified one on another.
        foreach ($probes as $probe) {
            foreach ($routes as $i => $route) {
                if (! self::isDomained($route) || ! $copies[$i]->matches($probe, false)) {
                    continue;
                }

                if (! self::verifies($route)) {
                    return $route;
                }
            }
        }

        return null;
    }

    /**
     * A throwaway route carrying only what decides capture: the URI, its constraints, and
     * the scheme restriction, which lives in the action array rather than the URI.
     *
     * NOT the live route. `matches()` compiles, `Route::$compiled` is written once and
     * nothing invalidates it — not `where()`, not `domain()`, not `prefix()` — so probing
     * the live object would freeze the regex that later binds its parameters, against
     * whatever the route looked like while the app was still booting.
     *
     * The domain is dropped rather than matched, per the rule above; with no host pattern
     * `HostValidator` passes and URI plus scheme decide. `prefix` goes with it because
     * `uri()` already carries the prefix and the constructor would apply it a second time.
     */
    private static function probeCopy(Route $route): Route
    {
        $action = Arr::except((array) $route->getAction(), ['domain', 'prefix']);

        return (new Route('POST', $route->uri(), $action))->setWheres($route->wheres);
    }

    /**
     * Both shapes our own route answers, crossed with both schemes.
     *
     * A host route whose parameter is REQUIRED (`wlcm/webhook/{anything}`) captures only the
     * form that carries a connection, so probing the bare path alone misses it. The values
     * are the configured connection names, ALL of them: our route constrains the segment to
     * that list, so a probe carrying anything else matches nothing of ours and would report
     * every host route standing behind it — and carrying only the first name left a host
     * route pinned to the second one unseen. The residual is the other direction: a host
     * route sitting on a segment that is NOT a configured connection goes unwatched, because
     * that URI is not one we serve either.
     *
     * Both schemes, because `SchemeValidator` refuses an http request for an https-only
     * route, and http alone therefore saw straight past one. The host in these URLs is
     * inert: probe copies carry no domain, so `HostValidator` never consults it.
     *
     * @param  list<string>  $connections
     * @return list<Request>
     */
    private static function probes(mixed $configuredPath, array $connections): array
    {
        $path = '/'.self::path($configuredPath);

        $paths = [$path];

        // rawurlencode is REQUIRED, not cosmetic — do not simplify it away. `Request::create()`
        // refuses a URI whose segment starts or ends with a space or holds a TAB/CR/LF, and
        // that throw comes out of the watch and takes the boot with it; the route compiles with
        // such a name perfectly well, so the brick would be ours alone. Encoding also makes a
        // non-ASCII name probeable at all, which it was not: `UriValidator` rawurldecodes
        // before matching, so encode-in/decode-out is identity for the matcher, and it is what
        // a real client sends — a raw space or CR/LF cannot appear in a request line.
        foreach ($connections as $connection) {
            $paths[] = $path.'/'.rawurlencode($connection);
        }

        $probes = [];

        foreach (['http', 'https'] as $scheme) {
            foreach ($paths as $candidate) {
                $probes[] = Request::create($scheme.'://localhost'.$candidate, 'POST');
            }
        }

        return $probes;
    }

    // Which of RouteCollection's two tables the route lives in.
    private static function isDomained(Route $route): bool
    {
        return (bool) $route->getDomain();
    }

    // Route-level middleware only, which is where both we and a deliberate host put it.
    private static function verifies(Route $route): bool
    {
        return in_array(VerifyWebhookSignature::class, (array) $route->middleware(), true);
    }
}
