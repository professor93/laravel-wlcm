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

        // COVERAGE DIVISION — read before removing this line.
        //
        // RefreshDatabase holds a transaction open for the whole test, so every suite
        // that uses it looks to the package exactly like a merchant calling from inside
        // DB::transaction(). Shipping default false would fail them all, so the suite
        // runs OPTED IN and therefore cannot observe the shipped default.
        //
        // What covers it instead:
        //   - tests/Feature/CallerTransactionTest.php turns this back off and proves the
        //     refusal fires on all three paths, including that the checkout guard runs
        //     before anything leaves the process.
        //   - tests/Unit/Support/CallerTransactionTest.php enumerates the whole
        //     cross-product of the rule's three inputs.
        //   - the e2e batteries run over real HTTP with no RefreshDatabase, so they
        //     exercise the shipped default as a merchant would meet it.
        $app['config']->set('wlcm.allow_caller_transaction', true);
    }
}
