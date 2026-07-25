<?php

declare(strict_types=1);

namespace Uzbek\Wlcm;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Uzbek\Wlcm\Storage\DatabaseLogStore;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionRecorder;

final class WlcmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wlcm.php', 'wlcm');

        $this->app->singleton(LogStore::class, DatabaseLogStore::class);
        $this->app->singleton(TransactionRecorder::class);

        $this->app->singleton(
            WlcmManager::class,
            fn (Application $app): WlcmManager => new WlcmManager(
                $app->make(Repository::class),
                $app->make(LogStore::class),
                $app->make(TransactionRecorder::class),
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

        if ((bool) $this->app->make(Repository::class)->get('wlcm.webhook.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        }
    }
}
