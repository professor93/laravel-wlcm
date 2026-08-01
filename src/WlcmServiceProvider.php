<?php

declare(strict_types=1);

namespace Uzbek\Wlcm;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Exceptions\WebhookRouteConflictException;
use Uzbek\Wlcm\Storage\CacheTransactionStore;
use Uzbek\Wlcm\Storage\DatabaseLogStore;
use Uzbek\Wlcm\Storage\FileLogStore;
use Uzbek\Wlcm\Storage\LogChannelLogStore;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionRecorder;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Support\ConfigToggle;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

final class WlcmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Recursive, not array_merge: a published config replaces whole sub-arrays
        // under a shallow merge, and every key it predates goes missing.
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/wlcm.php', 'wlcm');

        // singletonIf, not singleton, on all three. A host that binds a store BEFORE
        // providers register — which is exactly what
        // `Application::configure()->withSingletons([...])` does — was silently
        // overwritten here, so its audit sink never received anything and nothing said
        // so. Binding after us already worked; this makes the order stop mattering.
        $this->app->singletonIf(LogStore::class, function (Application $app): LogStore {
            // Singleton: driver fixed at first resolve; on/off toggle stays live.
            return match ($app->make(Repository::class)->get('wlcm.storage.logs', false)) {
                'file' => $app->make(FileLogStore::class),
                'log' => $app->make(LogChannelLogStore::class),
                'database', true, '1', false, '0', null, '' => $app->make(DatabaseLogStore::class),
                default => throw MissingConfigurationException::forKey('wlcm.storage.logs', '"database", "file", "log", true, "1" to log; false, "0", null, "" to disable', $app->make(Repository::class)->get('wlcm.storage.logs', false)),
            };
        });

        // Resolved once, from config, so 'cache' never reaches the row-writing recorder.
        // singletonIf, so a host binding registered before this provider still wins.
        $this->app->singletonIf(TransactionStore::class, function (Application $app): TransactionStore {
            $config = $app->make(Repository::class);

            return ConfigToggle::transactionsMode($config->get('wlcm.storage.transactions', false)) === 'cache'
                ? new CacheTransactionStore($app->make(CacheRepository::class), $config)
                : new TransactionRecorder($config);
        });

        $this->app->singletonIf(
            WlcmManager::class,
            fn (Application $app): WlcmManager => new WlcmManager(
                $app->make(Repository::class),
                $app->make(LogStore::class),
                $app->make(TransactionStore::class),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\DoctorCommand::class,
                Console\OnboardCommand::class,
                Console\WebhookCreateCommand::class,
                Console\WebhookListCommand::class,
                Console\WebhookUpdateCommand::class,
                Console\WebhookDeleteCommand::class,
                Console\WebhookEventsCommand::class,
                Console\MakeWebhookListenersCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/wlcm.php' => config_path('wlcm.php'),
        ], 'wlcm-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'wlcm-migrations');

        $enabled = $this->app->make(Repository::class)->get('wlcm.webhook.enabled', true);

        // Unreadable value stops the boot: a public route left serving by a typo
        // outlives any log line, and a dead app is the one failure nobody misses.
        if (ConfigToggle::enabled($enabled, 'wlcm.webhook.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
            $this->watchForRouteConflict();
        }
    }

    /**
     * A host route at the same method and URI evicts ours from the route collection and
     * takes VerifyWebhookSignature with it, leaving whatever replaced it reading an
     * unverified body. Nothing announces that; route:list is the only symptom.
     *
     * WATCHED FROM TWO POINTS, because neither one alone sees every table.
     *
     * booted() alone is too early under `route:cache` — which README calls the standard
     * production deploy, so the guard was alive in dev and dead in production.
     * `RouteServiceProvider::register()` queues a PROVIDER-level booted callback; with routes
     * cached, that callback queues `require bootstrap/cache/routes-v7.php` into the
     * APPLICATION booted queue, one level deeper and therefore later than anything a package
     * provider can queue from its own boot(). The order is fixed and unwinnable: ours is
     * queued first, the table installs second, and the watch walked an empty collection.
     *
     * `bootstrapped: BootProviders` fires after Application::boot() returns, with the cached
     * table in place — but only when the app boots through Application::bootstrapWith(), and
     * Orchestra Testbench calls the bootstrapper directly instead, so that event never fires
     * there. Hence both, with the report itself taken once: whichever point first sees a
     * shadow reports it, and neither point can make the other blind. Measured against a real
     * baked routes-v7.php in tests/Feature/WebhookRouteCacheConflictTest.php — Laravel's own
     * WithCachedRoutes trait installs the table synchronously and hides all of this.
     *
     * BOTH POINTS UNCONDITIONALLY, and the second one is not redundant when routes are NOT
     * cached, which is the tempting thing to believe: a host provider that registers its route
     * from an application booted() callback queued during its own boot() lands AFTER our
     * watch, and at an identical URI it evicts ours from routes['POST'] and serves deliveries
     * unverified. Measured: with the second point gated on routesAreCached() that shape
     * reports nothing while the host route serves; ungated it reports. The cost of keeping it
     * is one extra scan of the POST table per boot for installations with no conflict —
     * measured at 0.78ms of probe construction plus a few matches over 300 POST routes — and a
     * silent unverified endpoint is not worth trading for a millisecond.
     *
     * booted() also covers a provider registered after the app has booted: it runs the
     * callback immediately in that case, and by then the table is installed.
     *
     * This fires in CONSOLE too, and always did on the uncached path — the package boots for
     * `route:cache`, `migrate` and a scheduler tick like any other provider. A conflict will
     * therefore surface in deploy output. Kept deliberately: `route:cache` is the command that
     * creates the dangerous state, so it is the best possible moment to hear about it.
     *
     * Read the collection, never the name lookup: eviction mutates allRoutes, and an
     * evicted route still answers to its name, so a name-based check could not fail.
     *
     * The URI comes from CONFIG, not from our own registered route, because a table baked
     * while a host route already shadowed ours holds no route of ours to read the URI from.
     * The normalisation is not duplicated to get that; WebhookRoute owns the only copy and
     * the route file uses it too.
     */
    private function watchForRouteConflict(): void
    {
        $app = $this->app->make(FoundationApplication::class);
        $router = $this->app->make(Router::class);
        $config = $this->app->make(Repository::class);
        $path = $config->get('wlcm.webhook.path');
        $uri = WebhookRoute::uri($path);

        // Every configured connection name, because our route constrains the segment to that
        // list: a probe carrying a name outside it matches nothing of ours. Carrying only the
        // first left a host route pinned to any later connection unseen.
        $connections = array_map(
            static fn (string|int $connection): string => (string) $connection,
            array_keys((array) $config->get('wlcm.connections', [])),
        );

        // On the REPORT, not on the check: a point that saw no table has to leave the next
        // one free to look again.
        $reported = false;
        $failed = false;

        $watch = function () use (&$reported, &$failed, $router, $path, $connections, $uri): void {
            if ($reported) {
                return;
            }

            // THE WHOLE CHECK IS GUARDED, not just the report. The comment below used to sit
            // above `report()` alone while `shadow()` ran outside it — and `shadow()` builds
            // requests, constructs and compiles a throwaway route per host POST route, and
            // runs preg_match over all of them. Three surfaces that throw on pathological
            // config, each of which took the boot with it. Encoding the inputs closes the
            // instances known today; this closes the class.
            //
            // The failure is reported rather than swallowed, once: a watch that died is a
            // watch that is not watching, and this range has paid for enough silent guards.
            try {
                $shadow = WebhookRoute::shadow($router, $path, $connections);
            } catch (Throwable $e) {
                if (! $failed) {
                    $failed = true;
                    self::reportQuietly($e);
                }

                return;
            }

            if ($shadow === null) {
                return;
            }

            $reported = true;

            self::reportQuietly(WebhookRouteConflictException::forUri($uri));
        };

        $app->booted($watch);
        $app->afterBootstrapping(BootProviders::class, $watch);
    }

    /**
     * report() runs the host's handler, which can throw — an unwritable destination takes the
     * default channel down with the audit sink. A diagnostic may never be the thing that takes
     * a request down, so nothing here reaches the caller.
     */
    private static function reportQuietly(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            //
        }
    }
}
