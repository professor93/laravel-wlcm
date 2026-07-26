<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
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
            WebhookRoute::shadow($router, self::PATH, self::CONNECTION),
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
            WebhookRoute::shadow($router, self::PATH, self::CONNECTION) !== null,
            $reports ? 'an unverified endpoint went unreported' : 'a safe configuration was reported as a conflict',
        );
    }

    private function registerOurs(Router $router): void
    {
        $router->post(WebhookRoute::uri(self::PATH), fn (): string => 'ours')
            ->middleware(VerifyWebhookSignature::class)
            ->name(WebhookRoute::NAME)
            ->whereIn('connection', [self::CONNECTION]);
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
