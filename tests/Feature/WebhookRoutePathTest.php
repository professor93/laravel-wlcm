<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Uzbek\Wlcm\Tests\TestCase;

final class WebhookRoutePathTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('wlcm.webhook.path', ['array']);
    }

    public function test_array_typed_path_config_falls_back_to_default(): void
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '10.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", 'whsec_test');

        $this->postJson('/wlcm/webhook', $data)->assertOk();
    }
}
