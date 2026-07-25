<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Uzbek\Wlcm\Events\PaymentCancelled;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Events\SubscriptionActivated;
use Uzbek\Wlcm\Events\SubscriptionCancelled;
use Uzbek\Wlcm\Events\SubscriptionCharged;
use Uzbek\Wlcm\Events\SubscriptionChargeFailed;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;
use Uzbek\Wlcm\Webhooks\WlcmSubscriptionWebhookHandler;
use Uzbek\Wlcm\Webhooks\WlcmWebhookHandler;

final class WebhookTest extends TestCase
{
    private function payload(string $state = '2'): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '10.00', 'state' => $state, 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:{$state}:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    public function test_success_webhook_fires_events(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->payload('2'))->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->paymentId === '7788');
        Event::assertNotDispatched(PaymentCancelled::class);
    }

    public function test_cancelled_webhook(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->payload('-2'))->assertOk();

        Event::assertDispatched(PaymentCancelled::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_invalid_signature_rejected(): void
    {
        $data = $this->payload();
        $data['signature'] = str_repeat('0', 64);

        $this->postJson('/wlcm/webhook', $data)->assertForbidden();
    }

    public function test_config_handler_invoked(): void
    {
        $handler = new class implements WlcmWebhookHandler
        {
            public ?WebhookPayload $received = null;

            public function handle(WebhookPayload $payload): void
            {
                $this->received = $payload;
            }
        };
        $this->app->instance($handler::class, $handler);
        config()->set('wlcm.webhook.handler', $handler::class);

        $this->postJson('/wlcm/webhook', $this->payload())->assertOk();

        $this->assertNotNull($handler->received);
        $this->assertSame('7788', $handler->received->paymentId);
    }

    public function test_route_registered(): void
    {
        $this->assertTrue(app('router')->has('wlcm.webhook'));
    }

    public function test_unknown_connection_is_not_routed(): void
    {
        $this->postJson('/wlcm/webhook/nope', $this->payload())->assertNotFound();
    }

    public function test_known_connection_segment_still_verifies(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook/main', $this->payload('2'))->assertOk();

        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->connection === 'main');
    }

    public function test_handler_not_implementing_contract_throws(): void
    {
        config()->set('wlcm.webhook.handler', \stdClass::class);

        $this->withoutExceptionHandling();
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.webhook.handler');

        $this->postJson('/wlcm/webhook', $this->payload());
    }

    public function test_int_typed_payment_payload_end_to_end(): void
    {
        Event::fake();

        $data = [
            'external_id' => 'ORD-1', 'order_id' => 114, 'payment_id' => 98,
            'amount' => '100000.00', 'state' => 2, 'provider' => 'payme',
            'timestamp' => 1748765432000,
        ];
        $data['signature'] = hash_hmac('sha256', '114:98:2:1748765432000', 'whsec_test');

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->paymentId === '98'
            && $e->payload->amountTiyin() === 10000000);
    }

    private function subscriptionPayload(string $event = 'subscription.activated'): array
    {
        $data = [
            'event' => $event, 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        $data['signature'] = hash_hmac('sha256', "order-1001:{$event}:1745740800000", 'whsec_test');

        return $data;
    }

    public function test_subscription_activated_event(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload())->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(SubscriptionActivated::class, fn (SubscriptionActivated $e) => $e->payload->subscriptionId === 8);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentCancelled::class);
    }

    public function test_subscription_charged_and_failed_and_cancelled_events(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload('subscription.charged'))->assertOk();
        $this->postJson('/wlcm/webhook', $this->subscriptionPayload('subscription.charge_failed'))->assertOk();
        $this->postJson('/wlcm/webhook', $this->subscriptionPayload('subscription.cancelled'))->assertOk();

        Event::assertDispatched(SubscriptionCharged::class);
        Event::assertDispatched(SubscriptionChargeFailed::class);
        Event::assertDispatched(SubscriptionCancelled::class);
        Event::assertNotDispatched(SubscriptionActivated::class);
    }

    public function test_payment_handler_not_invoked_for_subscription_payload(): void
    {
        $handler = new class implements WlcmWebhookHandler
        {
            public ?WebhookPayload $received = null;

            public function handle(WebhookPayload $payload): void
            {
                $this->received = $payload;
            }
        };
        $this->app->instance($handler::class, $handler);
        config()->set('wlcm.webhook.handler', $handler::class);

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload())->assertOk();

        $this->assertNull($handler->received);
    }

    public function test_subscription_handler_invoked(): void
    {
        $handler = new class implements WlcmSubscriptionWebhookHandler
        {
            public ?SubscriptionWebhookPayload $received = null;

            public function handle(SubscriptionWebhookPayload $payload): void
            {
                $this->received = $payload;
            }
        };
        $this->app->instance($handler::class, $handler);
        config()->set('wlcm.webhook.subscription_handler', $handler::class);

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload())->assertOk();

        $this->assertNotNull($handler->received);
        $this->assertSame('subscription.activated', $handler->received->event);
    }

    public function test_subscription_handler_not_implementing_contract_throws(): void
    {
        config()->set('wlcm.webhook.subscription_handler', \stdClass::class);

        $this->withoutExceptionHandling();
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.webhook.subscription_handler');

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload());
    }

    public function test_real_captured_delivery_end_to_end(): void
    {
        // Verbatim tunnel capture: body-only signature, no webhook headers at all.
        config()->set('wlcm.connections.main.partner.webhook_secret', 'whsec_probe_2026');
        Event::fake();

        $this->postJson('/wlcm/webhook', [
            'external_id' => 'probe-wh2-170340', 'order_id' => 414, 'payment_id' => 172,
            'amount' => '3000.00', 'state' => 2, 'provider' => 'mock_payme',
            'timestamp' => 1784912687183,
            'signature' => 'c9ce779c9bab0543a1c9d1fa9d4ca5a3c2c4d3ee28b85d4d37aa384b6abd77c7',
        ])->assertOk();

        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->paymentId === '172'
            && $e->payload->amountTiyin() === 300000);
    }

    public function test_subscription_invalid_signature_rejected(): void
    {
        $data = $this->subscriptionPayload();
        $data['signature'] = str_repeat('0', 64);

        $this->postJson('/wlcm/webhook', $data)->assertForbidden();
    }
}
