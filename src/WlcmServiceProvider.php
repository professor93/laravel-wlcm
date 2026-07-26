<?php

declare(strict_types=1);

namespace Uzbek\Wlcm;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
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
     * A host route at the same method and URI evicts ours from the route collection and
     * takes VerifyWebhookSignature with it, leaving whatever replaced it reading an
     * unverified body. Nothing announces that; route:list is the only symptom.
     *
     * Deferred to booted() because the app's own routes load from a booted callback queued
     * by RouteServiceProvider::register(), and every register() precedes every boot(). Read
     * the collection, never the name lookup: eviction mutates allRoutes, but
     * refreshNameLookups() is queued in a nested booted() callback that runs after this
     * one, so the evicted route still answers to its name here and a name-based check
     * could not fail.
     *
     * The URI comes from CONFIG, not from our own registered route. Deriving it from our
     * route made the check blind under `route:cache`: a table baked while a host route
     * already shadowed ours holds no route of ours to read the URI from, so nothing was
     * watched — the guard was silent in precisely the state it exists to catch. The
     * normalisation is not duplicated to get that; WebhookRoute owns the only copy and the
     * route file uses it too.
     */
    private function watchForRouteConflict(): void
    {
        $router = $this->app->make(Router::class);
        $config = $this->app->make(Repository::class);
        $path = $config->get('wlcm.webhook.path');
        $uri = WebhookRoute::uri($path);

        // One real connection name, so the probe can exercise the form of our route that
        // carries a connection segment — the segment is whereIn-constrained to configured
        // names, so an invented value would not match our own route.
        $connections = array_keys((array) $config->get('wlcm.connections', []));
        $sample = $connections === [] ? null : (string) $connections[0];

        $this->app->booted(function () use ($router, $path, $sample, $uri): void {
            if (WebhookRoute::shadow($router, $path, $sample) === null) {
                return;
            }

            // Guarded: report() runs the host's handler, and a diagnostic may never be
            // the thing that takes a request down.
            try {
                report(WebhookRouteConflictException::forUri($uri));
            } catch (Throwable) {
                //
            }
        });
    }
}
