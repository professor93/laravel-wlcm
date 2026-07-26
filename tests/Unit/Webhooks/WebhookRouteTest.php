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
 * way to express the state that defeated the previous version: OUR ROUTE ABSENT and a host
 * route holding the URI. That is what `route:cache` produces when the table is baked while
 * the collision already exists, and no amount of registering routes in a live app can
 * reproduce it, because in a live app we always register ours.
 */
final class WebhookRouteTest extends TestCase
{
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
     * THE REGRESSION CASE. Our route is not in the table at all — exactly a cached table
     * baked while a host route already held the URI — and the shadow must still be found.
     * The previous detector learned the URI from our own route, so here it found nothing to
     * read, watched nothing, and reported nothing.
     */
    public function test_a_shadow_is_found_when_our_own_route_is_absent(): void
    {
        $router = $this->router();
        $router->post('wlcm/webhook/{connection?}', fn (): string => 'host');

        $shadow = WebhookRoute::shadow($router, WebhookRoute::uri('wlcm/webhook'));

        $this->assertNotNull($shadow, 'a host route holding the URI went unreported');
    }

    // Cached AND clean: only our own route in the table. Must stay quiet.
    public function test_our_own_route_is_not_a_shadow(): void
    {
        $router = $this->router();
        $router->post('wlcm/webhook/{connection?}', fn (): string => 'ours')
            ->middleware(VerifyWebhookSignature::class);

        $this->assertNull(WebhookRoute::shadow($router, WebhookRoute::uri('wlcm/webhook')));
    }

    // A host that reattaches the middleware has made a deliberate, safe choice.
    public function test_a_host_route_that_keeps_the_middleware_is_not_a_shadow(): void
    {
        $router = $this->router();
        $router->post('wlcm/webhook/{connection?}', fn (): string => 'host')
            ->middleware(VerifyWebhookSignature::class);

        $this->assertNull(WebhookRoute::shadow($router, WebhookRoute::uri('wlcm/webhook')));
    }

    /**
     * @return array<string, array{callable(Router): mixed}>
     *
     * States that must NOT report. Nothing-at-all is the case that keeps a merchant who
     * simply turned the endpoint off, or who cached a table for a different configured
     * path, from being told they have a security problem they do not have.
     */
    public static function quietTables(): array
    {
        return [
            'empty table' => [fn (Router $router): ?string => null],
            'neighbouring uri' => [fn (Router $router) => $router->post('wlcm/webhook', fn (): string => 'host')],
            'different uri' => [fn (Router $router) => $router->post('other/hook/{connection?}', fn (): string => 'host')],
            // Only a domainless POST can hold our slot: the collection keys on all three.
            'get not post' => [fn (Router $router) => $router->get('wlcm/webhook/{connection?}', fn (): string => 'host')],
            'domained' => [fn (Router $router) => $router->domain('other.test')->post('wlcm/webhook/{connection?}', fn (): string => 'host')],
        ];
    }

    /** @param callable(Router): mixed $build */
    #[DataProvider('quietTables')]
    public function test_states_that_must_not_report(callable $build): void
    {
        $router = $this->router();
        $build($router);

        $this->assertNull(WebhookRoute::shadow($router, WebhookRoute::uri('wlcm/webhook')));
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
