<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Throwable;
use Uzbek\Wlcm\Exceptions\WebhookRouteConflictException;
use Uzbek\Wlcm\Tests\Fixtures\CountingRouteCollection;
use Uzbek\Wlcm\Tests\Fixtures\RecordingExceptionHandler;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;
use Uzbek\Wlcm\Webhooks\WebhookRoute;
use Uzbek\Wlcm\WlcmServiceProvider;

/**
 * THE PRODUCTION CONFIGURATION: `php artisan route:cache`, which README calls the standard
 * deploy. Every other conflict test runs a live table, and the watch behaved differently
 * there — alive in dev, dead in production.
 *
 * The cached table does not exist while providers boot. `RouteServiceProvider::register()`
 * queues a provider-level booted callback; when routes are cached that callback queues
 * `require bootstrap/cache/routes-v7.php` into the APPLICATION booted queue — one level
 * deeper, therefore later, than anything a package provider can queue from its own `boot()`.
 * A watch sitting in the application booted queue walks an empty table.
 *
 * SO THIS TEST BOOTS A REAL APPLICATION AROUND A REAL CACHE FILE, baked exactly as
 * `RouteCacheCommand` writes it, and lets the real `RouteServiceProvider` install it on its
 * own schedule. `Illuminate\Foundation\Testing\WithCachedRoutes` would not do: it installs
 * the compiled table synchronously through `loadCachedRoutesUsing`, skipping the deferred
 * path entirely, so a test written with it passes over the defect.
 *
 * Not a Testbench case, because Testbench owns the container it boots and this needs a second
 * application with a base path of its own; the container and facade roots are restored after.
 */
final class WebhookRouteCacheConflictTest extends TestCase
{
    private string $base;

    private ?Container $previousContainer = null;

    private ?Application $previousFacadeRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $facadeRoot = Facade::getFacadeApplication();
        $this->previousFacadeRoot = $facadeRoot instanceof Application ? $facadeRoot : null;

        $this->base = sys_get_temp_dir().'/wlcm-route-cache-'.bin2hex(random_bytes(6));
        mkdir($this->base.'/bootstrap/cache', 0755, true);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeRoot);
        Container::setInstance($this->previousContainer);

        foreach (['/bootstrap/cache/routes-v7.php'] as $file) {
            if (file_exists($this->base.$file)) {
                unlink($this->base.$file);
            }
        }

        foreach (['/bootstrap/cache', '/bootstrap', ''] as $dir) {
            if (is_dir($this->base.$dir)) {
                rmdir($this->base.$dir);
            }
        }

        parent::tearDown();
    }

    /**
     * The table was baked while the host route already held the URI, so it contains no route
     * of ours at all — the state a merchant reaches by deploying the collision.
     */
    public function test_a_shadow_in_a_cached_table_is_reported(): void
    {
        $this->bakeCachedRoutes(function (RouteCollection $routes): void {
            $routes->add(new Route('POST', WebhookRoute::uri('wlcm/webhook'), ['uses' => 'HostController@handle']))
                ->name('host.collision');
        });

        [$app, $reported] = $this->bootApplication();

        // The premise, measured: routes really are cached, and the route that answers our
        // endpoint really does lack the signature middleware.
        $this->assertTrue($app->routesAreCached());
        $this->assertInstanceOf(CompiledRouteCollection::class, $app->make(Router::class)->getRoutes());
        $this->assertSame(
            ['host.collision'],
            array_values(array_map(
                fn (Route $route): ?string => $route->getName(),
                array_filter(
                    $app->make(Router::class)->getRoutes()->get('POST'),
                    fn (Route $route): bool => ! in_array(VerifyWebhookSignature::class, (array) $route->middleware(), true),
                ),
            )),
        );

        $this->assertNotEmpty(
            $this->conflicts($reported),
            'a cached table serving unverified webhook deliveries was not reported',
        );
    }

    /** The false-alarm side of the same path: a cached table where the endpoint is ours. */
    public function test_a_cached_table_holding_only_our_own_route_is_not_reported(): void
    {
        $this->bakeCachedRoutes(function (RouteCollection $routes): void {
            $routes->add(new Route('POST', WebhookRoute::uri('wlcm/webhook'), ['uses' => 'WebhookController@handle']))
                ->middleware(VerifyWebhookSignature::class)
                ->name(WebhookRoute::NAME)
                ->whereIn('connection', ['main']);
        });

        [$app, $reported] = $this->bootApplication();

        $this->assertTrue($app->routesAreCached());
        $this->assertSame([], $this->conflicts($reported), 'a verified cached endpoint was reported as a conflict');
    }

    /**
     * Two watch points, ONE report. Without a table to inspect the first point has to stay
     * silent and leave the second free to look, so the guard cannot latch on the check — it
     * latches on the report. This is the shape that reaches both with a shadow in front of
     * each: routes are cached, AND a host route is registered directly on the router before
     * boot, so the first point sees that one and the second sees the baked one.
     */
    public function test_a_shadow_both_watch_points_can_see_is_reported_once(): void
    {
        $this->bakeCachedRoutes(function (RouteCollection $routes): void {
            $routes->add(new Route('POST', WebhookRoute::uri('wlcm/webhook'), ['uses' => 'HostController@handle']))
                ->name('host.collision');
        });

        [$app, $reported] = $this->bootApplication(beforeBoot: function (Application $app): void {
            $app->make(Router::class)->post(WebhookRoute::uri('wlcm/webhook'), 'HostController@handle');
        });

        $this->assertTrue($app->routesAreCached());
        $this->assertCount(1, $this->conflicts($reported), 'one shadow, two watch points, more than one report');
    }

    /**
     * THE BOOT BUDGET, pinned because it is not free: `shadow()` asks the collection for the
     * POST table exactly once per pass, so this counts passes. With no route cache the table
     * is already final at booted(), and a second pass would cost every merchant who has no
     * conflict at all a full extra scan on every boot to buy nothing.
     */
    public function test_an_uncached_boot_scans_the_post_table_once(): void
    {
        $counting = new CountingRouteCollection;

        [$app] = $this->bootApplication(beforeBoot: function (Application $app) use ($counting): void {
            $app->make(Router::class)->setRoutes($counting);
        });

        $this->assertFalse($app->routesAreCached());
        $this->assertSame(['POST' => 1], $counting->scans);
    }

    /**
     * A provider registered after the application has booted never sees the bootstrapper
     * event that carries the deferred check, so the watch has to run there and then instead.
     * A guard on the new mechanism rather than a defect of the old one: `Application::booted()`
     * invokes immediately once booted, which is what used to cover this.
     *
     * The host route takes a REQUIRED parameter so that registering ours does not evict it —
     * at an identical URI ours would win the slot and there would be no shadow to find.
     */
    public function test_a_provider_registered_after_boot_still_watches(): void
    {
        [$app, $reported] = $this->bootApplication(registerPackage: false);

        $app->make(Router::class)->post('wlcm/webhook/{connection}', 'HostController@handle');
        $app->register(WlcmServiceProvider::class);

        $serving = $app->make(Router::class)->getRoutes()->match(
            Request::create('http://localhost/wlcm/webhook/main', 'POST'),
        );

        $this->assertNotContains(VerifyWebhookSignature::class, (array) $serving->middleware());
        $this->assertNotEmpty(
            $this->conflicts($reported),
            'a package registered after boot watched nothing',
        );
    }

    /** @param callable(RouteCollection): void $define */
    private function bakeCachedRoutes(callable $define): void
    {
        $routes = new RouteCollection;
        $define($routes);

        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        // Exactly what RouteCacheCommand does before writing the file.
        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        file_put_contents(
            $this->base.'/bootstrap/cache/routes-v7.php',
            "<?php\n\napp('router')->setCompiledRoutes(".var_export($routes->compile(), true).");\n",
        );
    }

    /**
     * Provider order is the real one: the package is discovered and registered before the
     * application's own RouteServiceProvider, whose register() is what defers the table.
     *
     * @param  (callable(Application): void)|null  $beforeBoot  Runs after the container is
     *                                                          wired and before providers boot.
     * @return array{Application, RecordingExceptionHandler}
     */
    private function bootApplication(bool $registerPackage = true, ?callable $beforeBoot = null): array
    {
        $app = new Application($this->base);

        // routesAreCached() and publishesMigrations() both reach for it.
        $app->instance('files', new Filesystem);

        // What RegisterFacades would do; routes/webhook.php registers through the facade.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->instance('config', new Repository([
            'wlcm' => [
                'connections' => ['main' => ['partner' => ['webhook_secret' => 'whsec_test']]],
            ],
        ]));

        $recorder = new RecordingExceptionHandler;
        $app->instance(ExceptionHandler::class, $recorder);

        if ($beforeBoot !== null) {
            $beforeBoot($app);
        }

        if ($registerPackage) {
            $app->register(WlcmServiceProvider::class);
        }

        $app->register(RouteServiceProvider::class);
        $app->bootstrapWith([BootProviders::class]);

        return [$app, $recorder];
    }

    /** @return list<Throwable> */
    private function conflicts(RecordingExceptionHandler $recorder): array
    {
        return $recorder->of(WebhookRouteConflictException::class);
    }
}
