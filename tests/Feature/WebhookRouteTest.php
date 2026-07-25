<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Routing\RouteCollection;
use Uzbek\Wlcm\Tests\TestCase;

final class WebhookRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('wlcm.connections', []);
    }

    public function test_empty_connections_config_still_compiles_routes(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertInstanceOf(RouteCollection::class, $routes);
        $routes->compile();
    }
}
