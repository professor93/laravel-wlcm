<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Fixtures;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * A host provider that registers its routes from an APPLICATION booted callback, queued
 * during its own boot() and therefore AFTER the package's watch callback. At an identical
 * URI it EVICTS the package route from `routes['POST']` — `addToCollections()` keys on
 * domain+URI — so it serves deliveries unverified while the watch, if it looked only at
 * booted(), saw the table one registration too early.
 *
 * A later route can only outrank ours this way. One at a different URI loses to ours at
 * dispatch for every real delivery, which is why the shape has to be eviction.
 */
final class LateRouteProvider extends ServiceProvider
{
    public static string $uri = 'wlcm/webhook/{connection?}';

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(Router::class)->post(self::$uri, 'LateHostController@handle')->name('host.late');
        });
    }
}
