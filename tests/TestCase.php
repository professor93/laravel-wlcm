<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Uzbek\Wlcm\WlcmServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WlcmServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('wlcm.connections.main.partner', [
            'base_url' => 'https://api.wlcm.test/api/v1',
            'api_key' => 'pk_test_key',
            'api_secret' => 'sk_test_secret',
            'webhook_secret' => 'whsec_test',
        ]);
        $app['config']->set('wlcm.webhook.tolerance_seconds', null);
    }
}
