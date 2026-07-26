<?php

declare(strict_types=1);

namespace Uzbek\Wlcm;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Exceptions\WebhookRouteConflictException;
use Uzbek\Wlcm\Storage\DatabaseLogStore;
use Uzbek\Wlcm\Storage\FileLogStore;
use Uzbek\Wlcm\Storage\LogChannelLogStore;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionRecorder;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Support\ConfigToggle;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;

final class WlcmServiceProvider extends ServiceProvider
{
    private const string WEBHOOK_ROUTE_NAME = 'wlcm.webhook';

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
                default => throw MissingConfigurationException::forKey('wlcm.storage.logs', '"database", "file", "log", true, "1" to log; false, "0", null, "" to disable'),
            };
        });

        $this->app->singletonIf(TransactionStore::class, TransactionRecorder::class);

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
     * A host route at the same method and URI evicts ours from the route collection
     * and takes VerifyWebhookSignature with it, leaving whatever replaced it reading
     * an unverified body. Nothing announces that; route:list is the only symptom.
     *
     * Deferred to booted() because the app's own routes load from a booted callback
     * queued by RouteServiceProvider::register(), and every register() precedes every
     * boot(). Read the collection, never the name lookup: eviction mutates allRoutes,
     * but refreshNameLookups() is queued in a nested booted() callback that runs after
     * this one, so the evicted route still answers to its name here and a name-based
     * check could not fail. What matters is the middleware on whoever holds the URI,
     * not whose route it is — a host that reattaches VerifyWebhookSignature is safe.
     */
    private function watchForRouteConflict(): void
    {
        $router = $this->app->make(Router::class);

        // Null means the routes were cached, so the load above was a no-op and there
        // is no URI to watch.
        $uri = $this->packageWebhookRoute($router)?->uri();

        if ($uri === null) {
            return;
        }

        $this->app->booted(function () use ($router, $uri): void {
            $serving = $this->routeServing($router, $uri);

            if ($serving === null || in_array(VerifyWebhookSignature::class, (array) $serving->middleware(), true)) {
                return;
            }

            // Guarded: report() runs the host's handler, and a diagnostic may never
            // be the thing that takes a request down.
            try {
                report(WebhookRouteConflictException::forUri($uri));
            } catch (Throwable) {
                //
            }
        });
    }

    private function packageWebhookRoute(Router $router): ?Route
    {
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getName() === self::WEBHOOK_ROUTE_NAME
                && in_array(VerifyWebhookSignature::class, (array) $route->middleware(), true)) {
                return $route;
            }
        }

        return null;
    }

    /** Only a domainless POST can hold our slot: the collection keys on methods, domain and uri. */
    private function routeServing(Router $router, string $uri): ?Route
    {
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getDomain() === null
                && $route->uri() === $uri
                && in_array('POST', $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
