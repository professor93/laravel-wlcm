<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

/**
 * The conflict detector, exercised against route tables built by hand — which is the only
 * way to express the states that defeated the previous versions: OUR ROUTE ABSENT (what
 * `route:cache` produces when the table is baked while the collision already exists, and
 * which no live app can reproduce because a live app always registers ours), and both
 * registration orders for every host shape.
 *
 * A correction worth keeping, because an earlier docblock here asserted the opposite:
 * "only a domainless POST can hold our slot" is FALSE. `RouteCollection` stores domained
 * routes in a separate table and `get($method)` returns
 * `domainRoutes[$method] + routes[$method]` — **domained first** — so a domained route at
 * our URI outranks ours whenever the Host matches. The earlier test used a domain that
 * could never match the request, so it proved only that a route which could not have taken
 * the slot did not take it.
 */
final class WebhookRouteTest extends TestCase
{
    private const string PATH = 'wlcm/webhook';

    private const string CONNECTION = 'main';

    private function router(): Router
    {
        return new Router(app(Dispatcher::class));
    }

    /** @return array<string, array{mixed, string}> */
    public static function configuredPaths(): array
    {
        return [
            'plain' => ['hooks/wlcm', 'hooks/wlcm'],
            'leading slash' => ['/hooks/wlcm', 'hooks/wlcm'],
            'trailing slash' => ['hooks/wlcm/', 'hooks/wlcm'],
            'both slashes' => ['/hooks/wlcm/', 'hooks/wlcm'],
            'padded' => ["  hooks/wlcm \t", 'hooks/wlcm'],
            // Each of these would otherwise register a ROOT catch-all.
            'empty' => ['', 'wlcm/webhook'],
            'slash only' => ['/', 'wlcm/webhook'],
            'many slashes' => ['///', 'wlcm/webhook'],
            'whitespace only' => ["  \t ", 'wlcm/webhook'],
            // An array reaching a string cast kills BootProviders.
            'array' => [['a'], 'wlcm/webhook'],
            'null' => [null, 'wlcm/webhook'],
            'int' => [7, 'wlcm/webhook'],
        ];
    }

    #[DataProvider('configuredPaths')]
    public function test_the_path_normalisation_is_the_one_the_route_file_uses(mixed $configured, string $expected): void
    {
        $this->assertSame($expected, WebhookRoute::path($configured));
        $this->assertSame($expected.'/{connection?}', WebhookRoute::uri($configured));
    }

    /**
     * THE REGRESSION CASE that started this file. Our route is not in the table at all —
     * exactly a cached table baked while a host route already held the URI — and the shadow
     * must still be found. An earlier detector learned the URI from our own route, so here
     * it found nothing to read, watched nothing and reported nothing.
     */
    public function test_a_shadow_is_found_when_our_own_route_is_absent(): void
    {
        $router = $this->router();
        $router->post(WebhookRoute::uri(self::PATH), fn (): string => 'host');

        $this->assertNotNull(
            WebhookRoute::shadow($router, self::PATH, [self::CONNECTION]),
            'a host route holding the URI went unreported',
        );
    }

    /**
     * @return array<string, array{callable(Router, string): mixed, bool, bool}>
     *
     * EVERY way a host can take a POST at our endpoint, crossed with both registration
     * orders. Hand-listing the shapes was the previous failure: only `Route::post()` was
     * covered, and it was the ONE shape the old detector got right.
     *
     * Expectations are MEASURED, not reasoned. The old detector scored 5 of these 16 cells:
     * 7 silent misses and 4 false alarms. The silent misses all came from `RouteCollection`
     * keeping two tables with different keys — `allRoutes` by method LIST, `routes[$method]`
     * by domain+URI — so any host route whose methods are not exactly ['POST'] evicted ours
     * from the matching table while leaving it alive in the scanned one, as a phantom that
     * still carried our middleware.
     *
     * The `false` rows matter as much as the `true` ones: they are the false-alarm guard. A
     * detector that reports whenever any route touches the URI would pass every `true` row
     * and fail these.
     */
    public static function hostShapes(): array
    {
        $shapes = [
            // name => [builder, reports when ours is registered FIRST, ... when LAST]
            'post' => [fn (Router $r, string $uri) => $r->post($uri, fn (): string => 'host'), true, false],
            'any' => [fn (Router $r, string $uri) => $r->any($uri, fn (): string => 'host'), true, false],
            'match post+get' => [fn (Router $r, string $uri) => $r->match(['POST', 'GET'], $uri, fn (): string => 'host'), true, false],
            // redirect() IS any() — literally any($uri, RedirectController::class) — so a
            // merchant redirecting a moved webhook path hit the silent case.
            'redirect' => [fn (Router $r, string $uri) => $r->redirect($uri, '/moved'), true, false],
            'permanentRedirect' => [fn (Router $r, string $uri) => $r->permanentRedirect($uri, '/moved'), true, false],
            // Domained routes are returned FIRST by get($method), so one at our URI
            // outranks ours whenever the Host matches — either order reports.
            'domained post' => [fn (Router $r, string $uri) => $r->domain('localhost')->post($uri, fn (): string => 'host'), true, true],
            // A REQUIRED param captures the connection-bearing form only, which is why one
            // probe shape was not enough. Registered after ours it wins; before, it loses.
            'param wildcard' => [fn (Router $r, string $uri) => $r->post(self::PATH.'/{anything}', fn (): string => 'host'), false, true],
            // Safe: a GET route cannot receive our POST, whatever the order.
            'get only' => [fn (Router $r, string $uri) => $r->get($uri, fn (): string => 'host'), false, false],
            // Safe and load-bearing: a host that re-registers the path AND reattaches the
            // middleware has made a deliberate, verified choice. This is the whole reason
            // the predicate keys on the middleware rather than on whose route it is —
            // reporting here would punish the correct way to front the endpoint.
            'post that reattaches our middleware' => [
                fn (Router $r, string $uri) => $r->post($uri, fn (): string => 'host')->middleware(VerifyWebhookSignature::class),
                false,
                false,
            ],
            // Baseline: nobody else touches it.
            'no host route at all' => [fn (Router $r, string $uri): ?string => null, false, false],
        ];

        $cases = [];

        foreach ($shapes as $name => [$build, $whenOursFirst, $whenOursLast]) {
            $cases["{$name} | ours first"] = [$build, true, $whenOursFirst];
            $cases["{$name} | ours last"] = [$build, false, $whenOursLast];
        }

        return $cases;
    }

    /** @param callable(Router, string): mixed $build */
    #[DataProvider('hostShapes')]
    public function test_every_host_shape_in_both_orders(callable $build, bool $oursFirst, bool $reports): void
    {
        $router = $this->router();
        $uri = WebhookRoute::uri(self::PATH);

        if ($oursFirst) {
            $this->registerOurs($router);
            $build($router, $uri);
        } else {
            $build($router, $uri);
            $this->registerOurs($router);
        }

        $this->assertSame(
            $reports,
            WebhookRoute::shadow($router, self::PATH, [self::CONNECTION]) !== null,
            $reports ? 'an unverified endpoint went unreported' : 'a safe configuration was reported as a conflict',
        );
    }

    /**
     * EVERY DOMAINED SHAPE, plus the two shapes the probe set could not reach. The previous
     * matrix had exactly one domained cell and it used `domain('localhost')` — the one domain
     * `Request::create()`'s default host matches — so the domained branch was never the thing
     * under test, and it under-reported every shape below.
     *
     * `serves` is MEASURED here, by the same `getRoutes()->match()` dispatch uses, and it is
     * asserted alongside the verdict: a row claiming the host takes the endpoint has to prove
     * it first, or the expectation is worthless.
     *
     * @return array<string, array{callable(Router): mixed, list<string>, bool, string, string, bool}>
     */
    public static function reachShapes(): array
    {
        $uri = WebhookRoute::uri(self::PATH);
        $domain = 'api.example.com';
        $delivery = "http://{$domain}/wlcm/webhook/main";

        return [
            // name => [host route, configured connections, ours first, delivery URL, who serves it, reports]
            'domained post at our uri' => [
                fn (Router $r) => $r->domain($domain)->post($uri, 'H@h'),
                ['main'], true, $delivery, 'host-unverified', true,
            ],
            'domained post, parameter renamed' => [
                fn (Router $r) => $r->domain($domain)->post(self::PATH.'/{conn?}', 'H@h'),
                ['main'], true, $delivery, 'host-unverified', true,
            ],
            'domained post, parameter required' => [
                fn (Router $r) => $r->domain($domain)->post(self::PATH.'/{connection}', 'H@h'),
                ['main'], true, $delivery, 'host-unverified', true,
            ],
            'domained post, bare path with no parameter' => [
                fn (Router $r) => $r->domain($domain)->post(self::PATH, 'H@h'),
                ['main'], true, "http://{$domain}/wlcm/webhook", 'host-unverified', true,
            ],
            'domained any catch-all' => [
                fn (Router $r) => $r->domain($domain)->any('{any}', 'H@h')->where('any', '.*'),
                ['main'], true, $delivery, 'host-unverified', true,
            ],
            'domained post wlcm/{rest}' => [
                fn (Router $r) => $r->domain($domain)->post('wlcm/{rest}', 'H@h')->where('rest', '.*'),
                ['main'], true, $delivery, 'host-unverified', true,
            ],
            'patterned domain, post at our uri' => [
                fn (Router $r) => $r->domain('{tenant}.example.com')->post($uri, 'H@h'),
                ['main'], true, 'http://t1.example.com/wlcm/webhook/main', 'host-unverified', true,
            ],
            'patterned domain, parameter renamed and required' => [
                fn (Router $r) => $r->domain('{tenant}.example.com')->post(self::PATH.'/{c}', 'H@h'),
                ['main'], true, 'http://t1.example.com/wlcm/webhook/main', 'host-unverified', true,
            ],
            // The prefix lands on the URI, so a probe copy must not re-apply it.
            'domained group prefix' => [
                fn (Router $r) => $r->domain($domain)->prefix('wlcm')->post('webhook/{connection?}', 'H@h'),
                ['main'], true, $delivery, 'host-unverified', true,
            ],
            // Second configured connection: the probe set used to carry connections[0] only.
            'literal second connection segment' => [
                fn (Router $r) => $r->post(self::PATH.'/alt', 'H@h'),
                ['main', 'alt'], false, 'http://localhost/wlcm/webhook/alt', 'host-unverified', true,
            ],
            'parameter constrained to the second connection' => [
                fn (Router $r) => $r->post(self::PATH.'/{c}', 'H@h')->whereIn('c', ['alt']),
                ['main', 'alt'], false, 'http://localhost/wlcm/webhook/alt', 'host-unverified', true,
            ],
            // Scheme lives in the action array, so an https-only route answered neither probe.
            'https-only post at our uri' => [
                fn (Router $r) => $r->post($uri, ['https', 'uses' => 'H@h']),
                ['main'], true, 'https://localhost/wlcm/webhook/main', 'host-unverified', true,
            ],

            // Why the domainless pass ignores domained routes rather than walking one table:
            // `get('POST')` returns domained routes FIRST, so a verifying domained route
            // would stop the walk before the domainless route that really takes the endpoint
            // on every other host.
            'verifying domained route does not mask a domainless one' => [
                function (Router $r) use ($uri, $domain): void {
                    $r->post(self::PATH.'/{c}', 'H@h');
                    $r->domain($domain)->post($uri, 'H@h')->middleware(VerifyWebhookSignature::class);
                },
                ['main'], false, 'http://other.example.com/wlcm/webhook/main', 'host-unverified', true,
            ],

            // --- false-alarm guards: these must stay silent.
            // `domain('')` — what `domain(config(...))` yields from an unset key — matches
            // every host, and `RouteCollection` files it under the DOMAINLESS table. Treating
            // it as domained would skip the ordering that lets our verifying route win.
            'empty domain at a capturing uri, ours first' => [
                fn (Router $r) => $r->domain('')->post('wlcm/{rest}', 'H@h')->where('rest', '.*'),
                ['main'], true, 'http://localhost/wlcm/webhook/main', 'package', false,
            ],
            'domained post that reattaches our middleware' => [
                fn (Router $r) => $r->domain($domain)->post($uri, 'H@h')->middleware(VerifyWebhookSignature::class),
                ['main'], true, $delivery, 'host-verified', false,
            ],
            // Ours is domainless, so it answers on that host too — which is the point of the row.
            'domained post at an unrelated uri' => [
                fn (Router $r) => $r->domain($domain)->post('billing/callback', 'H@h'),
                ['main'], true, $delivery, 'package', false,
            ],
            'domained get at our uri' => [
                fn (Router $r) => $r->domain($domain)->get($uri, 'H@h'),
                ['main'], true, $delivery, 'package', false,
            ],
            'literal second connection segment, ours first' => [
                fn (Router $r) => $r->post(self::PATH.'/alt', 'H@h'),
                ['main', 'alt'], true, 'http://localhost/wlcm/webhook/alt', 'package', false,
            ],
        ];
    }

    /**
     * @param  callable(Router): mixed  $build
     * @param  list<string>  $connections
     */
    #[DataProvider('reachShapes')]
    public function test_every_domained_and_scheme_shape(
        callable $build,
        array $connections,
        bool $oursFirst,
        string $delivery,
        string $serves,
        bool $reports,
    ): void {
        $router = $this->router();

        if ($oursFirst) {
            $this->registerOurs($router, $connections);
            $build($router);
        } else {
            $build($router);
            $this->registerOurs($router, $connections);
        }

        $this->assertSame(
            $reports,
            WebhookRoute::shadow($router, self::PATH, $connections) !== null,
            $reports ? 'an unverified endpoint went unreported' : 'a safe configuration was reported as a conflict',
        );

        $this->assertSame($serves, $this->serves($router, $delivery), 'the row describes a delivery it does not produce');
    }

    /**
     * The residual behind the whole domained branch, asserted through dispatch rather than
     * through the private state: `Route::$compiled` is written once and nothing invalidates
     * it — not `where()`, not `domain()`, not `prefix()` — so a watch that compiles a LIVE
     * route freezes the regex that later binds its parameters. Any constraint the route
     * acquires afterwards is then ignored at dispatch, and an unconstrained regex accepts
     * what the constraint would have refused. Probing copies leaves the live table untouched.
     */
    public function test_probing_leaves_constraints_declared_after_it_binding(): void
    {
        $router = $this->router();
        $this->registerOurs($router, [self::CONNECTION]);
        $host = $router->domain('api.example.com')->post(self::PATH.'/{connection}', 'H@h');

        WebhookRoute::shadow($router, self::PATH, [self::CONNECTION]);

        $host->whereIn('connection', ['main']);

        $this->assertSame(
            'none',
            $this->serves($router, 'http://api.example.com/wlcm/webhook/other'),
            'a constraint declared after the watch ran was ignored at dispatch',
        );
    }

    /**
     * @return array<string, array{string}>
     *
     * Connection names the probe set has to survive AND reach the matcher with. Widening the
     * probe set from `connections[0]` to every configured name made every name reachable —
     * and reachability turned two of these from inert into fatal: `Request::create()` refuses
     * a URI whose segment starts or ends with a space, or contains TAB/CR/LF, and that throw
     * came straight out of the watch and took the boot with it. The route itself compiles
     * fine with those names, so the brick was the watch's alone.
     *
     * The unicode row is the other direction: it never threw, it simply never matched, so the
     * endpoint of a non-ASCII connection could be shadowed without a word.
     */
    public static function connectionNames(): array
    {
        return [
            'plain' => ['main'],
            'dotted' => ['my.conn'],
            'inner space' => ['a b'],
            'single space' => [' '],
            'tab' => ["\t"],
            'non-ascii' => ['асосий'],
        ];
    }

    /**
     * A host route constrained to THIS connection alone, so the row can only pass if the probe
     * carrying that name was built and reached the matcher.
     */
    #[DataProvider('connectionNames')]
    public function test_every_configured_connection_name_is_probed(string $connection): void
    {
        $router = $this->router();
        $router->post(self::PATH.'/{c}', 'H@h')->whereIn('c', [preg_quote($connection, '/')]);
        $this->registerOurs($router, [$connection]);

        $this->assertNotNull(
            WebhookRoute::shadow($router, self::PATH, [$connection]),
            'the probe for this connection never reached the matcher',
        );
    }

    /**
     * @return array<string, array{string}>
     *
     * The OTHER input that reaches `Request::create()`. Encoding the connection segment left
     * this one raw, and `path()` trims only the ENDS, so an interior control character
     * survives — a `WLCM_WEBHOOK_PATH` carrying a tab or CR from a wiki paste or a CRLF
     * `.env`. The route registers with such a path perfectly well, so the brick is the
     * watch's alone, which is the same sentence the connection-segment fix was written for.
     */
    public static function configuredPathsThatMustNotBrick(): array
    {
        return [
            'plain' => ['wlcm/webhook'],
            'inner space' => ['wlcm/web hook'],
            'inner tab' => ["wlcm/web\thook"],
            'inner cr' => ["wlcm/web\rhook"],
            'inner lf' => ["wlcm/web\nhook"],
            'non-ascii' => ['wlcm/веб'],
        ];
    }

    #[DataProvider('configuredPathsThatMustNotBrick')]
    public function test_a_configured_path_never_takes_the_boot_down(string $configured): void
    {
        $router = $this->router();
        $router->post(WebhookRoute::uri($configured), 'H@h')->name('host.bare');

        $this->assertNotNull(
            WebhookRoute::shadow($router, $configured, [self::CONNECTION]),
            'the probe for this configured path never reached the matcher',
        );
    }

    /**
     * THE MISSING `break` IS LOAD-BEARING. Making the two passes symmetric is the most
     * tempting refactor in the file and it passes every other test: a domained route answers
     * only on its own host, so a VERIFYING one on `admin.example.com` says nothing whatever
     * about an unverified one on `api.example.com`, which is where WLCM actually delivers.
     * Breaking on the first capture would stop at the admin route and report nothing.
     */
    public function test_a_verified_domained_route_does_not_end_the_domained_pass(): void
    {
        $router = $this->router();
        $uri = WebhookRoute::uri(self::PATH);

        $router->domain('admin.example.com')->post($uri, 'H@h')
            ->middleware(VerifyWebhookSignature::class)->name('host.admin');
        $router->domain('api.example.com')->post($uri, 'H@h')->name('host.api');
        $this->registerOurs($router, [self::CONNECTION]);

        $this->assertSame('host-unverified', $this->serves($router, 'http://api.example.com/wlcm/webhook/main'));
        $this->assertNotNull(
            WebhookRoute::shadow($router, self::PATH, [self::CONNECTION]),
            'a verifying route on one host silenced an unverified route on another',
        );
    }

    /** Who answers the delivery, decided by the same matcher dispatch uses. */
    private function serves(Router $router, string $url): string
    {
        try {
            $route = $router->getRoutes()->match(Request::create($url, 'POST'));
        } catch (Throwable) {
            return 'none';
        }

        return match (true) {
            $route->getName() === WebhookRoute::NAME => 'package',
            in_array(VerifyWebhookSignature::class, (array) $route->middleware(), true) => 'host-verified',
            default => 'host-unverified',
        };
    }

    /** @param list<string> $connections */
    private function registerOurs(Router $router, array $connections = [self::CONNECTION]): void
    {
        $router->post(WebhookRoute::uri(self::PATH), fn (): string => 'ours')
            ->middleware(VerifyWebhookSignature::class)
            ->name(WebhookRoute::NAME)
            ->whereIn('connection', $connections);
    }

    /**
     * The drift guard between the two users of this class. The watch checks a URI derived
     * from CONFIG; the route file registers one built from the same helper. If those ever
     * stop agreeing, the watch inspects a URI nobody serves and goes permanently quiet —
     * the same silent-guard failure, arrived at from the other direction.
     */
    public function test_the_derived_uri_matches_the_route_actually_registered(): void
    {
        $registered = null;

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if ($route->getName() === WebhookRoute::NAME) {
                $registered = $route->uri();
            }
        }

        $this->assertNotNull($registered, 'the package route is not registered');
        $this->assertSame($registered, WebhookRoute::uri(config('wlcm.webhook.path')));
    }
}
