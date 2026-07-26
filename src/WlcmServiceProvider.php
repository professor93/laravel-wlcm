<?php

declare(strict_types=1);

namespace Uzbek\Wlcm;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Storage\DatabaseLogStore;
use Uzbek\Wlcm\Storage\FileLogStore;
use Uzbek\Wlcm\Storage\LogChannelLogStore;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionRecorder;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Support\ConfigToggle;

final class WlcmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wlcm.php', 'wlcm');

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
                default => throw MissingConfigurationException::forKey('wlcm.storage.logs'),
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
        }
    }
}
