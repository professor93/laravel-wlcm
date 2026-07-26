<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Tests\TestCase;

/**
 * `wlcm.webhook.enabled` is consulted at BOOT, when routes are loaded. With
 * `php artisan route:cache` the table is already baked, boot never runs that check, and
 * the served process reports `enabled: false` while happily accepting signed deliveries —
 * events and all. The README stated flatly that `false` does not register the route.
 *
 * These tests set the flag AFTER the route table exists, which is precisely the cached
 * state: routes built while enabled, config now saying otherwise.
 */
final class WebhookDisabledTest extends TestCase
{
    public function test_a_delivery_is_refused_when_the_toggle_is_off_behind_a_built_route_table(): void
    {
        Event::fake();
        config(['wlcm.webhook.enabled' => false]);

        $this->postJson('/wlcm/webhook', $this->signed())->assertNotFound();

        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    // Off is off however it is spelled, same vocabulary the boot check uses.
    public function test_every_documented_off_value_closes_the_endpoint(): void
    {
        foreach ([false, '0', 0, null, ''] as $off) {
            config(['wlcm.webhook.enabled' => $off]);

            $this->postJson('/wlcm/webhook', $this->signed())
                ->assertNotFound();
        }
    }

    // A value the package cannot read must not resolve to "serving". Loud, like boot.
    public function test_an_unreadable_toggle_throws_rather_than_serving(): void
    {
        config(['wlcm.webhook.enabled' => 'off']);
        $this->withoutExceptionHandling();

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.webhook.enabled');

        $this->postJson('/wlcm/webhook', $this->signed());
    }

    public function test_the_endpoint_still_serves_when_the_toggle_is_on(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->signed())->assertOk();

        Event::assertDispatched(WebhookReceived::class);
    }

    /** @return array<string, string> */
    private function signed(): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', '1254:7788:2:2026-07-24 12:00:00', 'whsec_test');

        return $data;
    }
}
