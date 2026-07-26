<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use ErrorException;
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
use Uzbek\Wlcm\Tests\Fixtures\LateRouteProvider;
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
     * A host route registered from an application booted() callback queued AFTER ours — a
     * provider that defers its route registration — evicts the package route at an identical
     * URI and serves deliveries unverified. booted() alone sees the table one registration too
     * early, so this is the row that makes the SECOND watch point load-bearing even with no
     * route cache, where it otherwise looks like a pure duplicate.
     */
    public function test_a_route_registered_after_the_watch_callback_is_still_reported(): void
    {
        [$app, $reported] = $this->bootApplication(alsoRegister: LateRouteProvider::class);

        $serving = $app->make(Router::class)->getRoutes()->match(
            Request::create('http://localhost/wlcm/webhook/main', 'POST'),
        );

        $this->assertSame('host.late', $serving->getName(), 'the late route did not take the endpoint');
        $this->assertNotContains(VerifyWebhookSignature::class, (array) $serving->middleware());
        $this->assertNotEmpty($this->conflicts($reported), 'a late eviction served unverified and went unreported');
    }

    /**
     * THE BOOT BUDGET, pinned because it is not free: `shadow()` asks the collection for the
     * POST table exactly once per pass, so this counts passes. Two is deliberate and the row
     * above is why; a THIRD would be someone adding a watch point without noticing the cost,
     * which is what this guards.
     */
    public function test_a_boot_scans_the_post_table_once_per_watch_point(): void
    {
        $counting = new CountingRouteCollection;

        [$app] = $this->bootApplication(beforeBoot: function (Application $app) use ($counting): void {
            $app->make(Router::class)->setRoutes($counting);
        });

        $this->assertFalse($app->routesAreCached());
        $this->assertSame(['POST' => 2], $counting->scans);
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

    /**
     * THE WATCH MAY NEVER BE THE THING THAT TAKES THE APP DOWN, which is what the guard's
     * comment has always claimed while `shadow()` ran outside it. `shadow()` builds requests,
     * compiles a throwaway route per host POST route and runs preg_match over all of them.
     *
     * A connection name ending in `$` reaches the third surface: `preg_quote()` escapes it to
     * `\$`, and `symfony/routing/Route.php:456-457` strips a trailing `$` from a requirement
     * WITHOUT checking whether it is escaped, leaving a dangling backslash that fails to
     * compile when `preg_match` runs it. Encoding the inputs cannot help here — the value is
     * legal in a URI and breaks in the regex — so only the guard keeps the app alive.
     */
    public function test_a_watch_that_throws_does_not_take_the_boot_down(): void
    {
        // `preg_match` WARNS and returns false rather than throwing, so the failure only
        // becomes an exception because `HandleExceptions:71-78` promotes warnings — which
        // every booted Laravel app does and this cut-down harness does not. Installing the
        // same promotion is what makes the test reproduce production rather than a quieter
        // version of it; without it `shadow()` returns null silently and the guard is never
        // reached.
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            [$app, $reported] = $this->bootApplication(connections: ['main', 'bad$']);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($app->isBooted(), 'the watch took the boot down');
        $this->assertSame([], $this->conflicts($reported), 'a failed watch reported a conflict it never established');
        // ONCE, not twice: both watch points run and both hit the same broken requirement,
        // so the failure latches exactly as the conflict report does. Two latches rather than
        // one, deliberately — sharing them would let a failure at the first point suppress a
        // genuine conflict found at the second.
        $this->assertCount(
            1,
            $reported->reported,
            'the watch died silently, or said so once per watch point',
        );
    }

    /**
     * THE TWO LATCHES ARE SEPARATE, and this is the shape that proves it has to stay that
     * way. The watch points do not see the same table: under `route:cache` the first walks
     * the live one and the second walks the compiled one that REPLACES it. So a watch that
     * dies at the first point can be followed by a genuine conflict at the second, and
     * latching the failure on `$reported` would spend the conflict report on a check that
     * never established anything — the deployed-collision state, silent.
     *
     * The decoy carries the same broken requirement as the test above and lives only in the
     * live table, so it kills the first point and is gone before the second.
     */
    public function test_a_watch_failure_at_one_point_does_not_suppress_a_conflict_at_the_other(): void
    {
        $this->bakeCachedRoutes(function (RouteCollection $routes): void {
            $routes->add(new Route('POST', WebhookRoute::uri('wlcm/webhook'), ['uses' => 'HostController@handle']))
                ->name('host.collision');
        });

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            [$app, $reported] = $this->bootApplication(beforeBoot: function (Application $app): void {
                $app->make(Router::class)->post('decoy/{x}', 'HostController@handle')->where('x', 'bad\\$');
            });
        } finally {
            restore_error_handler();
        }

        // The premises, measured: a green here must not be reachable by the scenario
        // failing to set itself up.
        $this->assertTrue($app->routesAreCached(), 'premise: routes are cached');
        $this->assertNotEmpty($reported->of(ErrorException::class), 'premise: the first watch point did not fail');
        $this->assertNotNull(
            $app->make(Router::class)->getRoutes()->getByName('host.collision'),
            'premise: the compiled table holding the collision was never installed',
        );

        $this->assertNotEmpty(
            $this->conflicts($reported),
            'a watch failure at one point suppressed a genuine conflict found at the other',
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
     * @param  class-string|null  $alsoRegister  A host provider, registered after ours.
     * @param  list<string>  $connections  Configured connection names.
     * @return array{Application, RecordingExceptionHandler}
     */
    private function bootApplication(bool $registerPackage = true, ?callable $beforeBoot = null, ?string $alsoRegister = null, array $connections = ['main']): array
    {
        $app = new Application($this->base);

        // routesAreCached() and publishesMigrations() both reach for it.
        $app->instance('files', new Filesystem);

        // What RegisterFacades would do; routes/webhook.php registers through the facade.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $app->instance('config', new Repository([
            'wlcm' => [
                'connections' => array_fill_keys($connections, ['partner' => ['webhook_secret' => 'whsec_test']]),
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

        if ($alsoRegister !== null) {
            $app->register($alsoRegister);
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
