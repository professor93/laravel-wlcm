<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class VerifySignatureTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function request(array $data): Request
    {
        $request = Request::create('/wlcm/webhook', 'POST', $data);

        return $request;
    }

    private function validData(): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '10.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    private function dataStampedAt(string $timestamp): array
    {
        $data = $this->validData();
        $data['timestamp'] = $timestamp;
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$timestamp}", 'whsec_test');

        return $data;
    }

    public function test_valid_signature_passes_and_sets_payload(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $request = $this->request($this->validData());

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(WebhookPayload::class, $request->attributes->get('wlcm_payload'));
    }

    public function test_tampered_signature_403(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->validData();
        $data['amount'] = '99999.00';
        $data['signature'] = hash_hmac('sha256', 'wrong:base:2:x', 'whsec_test');
        $data['order_id'] = '9999';

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_missing_fields_422(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);

        try {
            $middleware->handle($this->request(['order_id' => '1']), fn ($r) => response()->json());
            $this->fail('expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_array_field_rejected_422(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->validData();
        $data['signature'] = ['x'];

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_missing_secret_fails_closed(): void
    {
        config()->set('wlcm.connections.main.partner.webhook_secret', null);
        $middleware = $this->app->make(VerifyWebhookSignature::class);

        $this->expectException(MissingConfigurationException::class);
        $middleware->handle($this->request($this->validData()), fn ($r) => response()->json());
    }

    public function test_int_typed_fields_pass(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = [
            'external_id' => 'ORD-1', 'order_id' => 114, 'payment_id' => 98,
            'amount' => '100000.00', 'state' => 2, 'provider' => 'payme',
            'timestamp' => 1748765432000,
        ];
        $data['signature'] = hash_hmac('sha256', '114:98:2:1748765432000', 'whsec_test');

        $response = $middleware->handle($this->request($data), fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_header_signature_used_when_body_field_missing(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->validData();
        $signature = $data['signature'];
        unset($data['signature']);

        $request = Request::create('/wlcm/webhook', 'POST', $data, [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ]);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $request->attributes->get('wlcm_payload');
        $this->assertInstanceOf(WebhookPayload::class, $payload);
        $this->assertSame($signature, $payload->signature);
    }

    public function test_missing_signature_everywhere_422(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->validData();
        unset($data['signature']);

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_real_captured_delivery_verifies(): void
    {
        // Verbatim tunnel capture (order 414 / payment 172): JSON ints, signature
        // in the body only — real deliveries send no X-Webhook-Signature header.
        config()->set('wlcm.connections.main.partner.webhook_secret', 'whsec_probe_2026');
        $middleware = $this->app->make(VerifyWebhookSignature::class);

        $request = $this->request([
            'external_id' => 'probe-wh2-170340', 'order_id' => 414, 'payment_id' => 172,
            'amount' => '3000.00', 'state' => 2, 'provider' => 'mock_payme',
            'timestamp' => 1784912687183,
            'signature' => 'c9ce779c9bab0543a1c9d1fa9d4ca5a3c2c4d3ee28b85d4d37aa384b6abd77c7',
        ]);

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $request->attributes->get('wlcm_payload');
        $this->assertInstanceOf(WebhookPayload::class, $payload);
        $this->assertSame('414:172:2:1784912687183', $payload->signatureBase());
        $this->assertSame(300000, $payload->amountTiyin());
    }

    public function test_missing_amount_422(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->validData();
        unset($data['amount']);

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('Missing field: amount', $e->getMessage());
        }
    }

    public function test_stale_timestamp_403_when_tolerance_set(): void
    {
        config()->set('wlcm.webhook.tolerance_seconds', 300);
        Carbon::setTestNow('2026-07-25 12:00:00');
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->dataStampedAt((string) now()->subSeconds(301)->getTimestampMs());

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Stale webhook timestamp', $e->getMessage());
        }
    }

    public function test_fresh_timestamp_passes_when_tolerance_set(): void
    {
        config()->set('wlcm.webhook.tolerance_seconds', 300);
        Carbon::setTestNow('2026-07-25 12:00:00');
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $request = $this->request($this->dataStampedAt((string) now()->subSeconds(60)->getTimestampMs()));

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(WebhookPayload::class, $request->attributes->get('wlcm_payload'));
    }

    public function test_unparseable_timestamp_403_when_tolerance_set(): void
    {
        config()->set('wlcm.webhook.tolerance_seconds', 300);
        $middleware = $this->app->make(VerifyWebhookSignature::class);

        try {
            $middleware->handle($this->request($this->dataStampedAt('not-a-time')), fn ($r) => response()->json());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Stale webhook timestamp', $e->getMessage());
        }
    }

    public function test_null_tolerance_accepts_ancient_timestamp(): void
    {
        config()->set('wlcm.webhook.tolerance_seconds', null);
        $middleware = $this->app->make(VerifyWebhookSignature::class);

        $response = $middleware->handle(
            $this->request($this->dataStampedAt('2020-01-01 00:00:00')),
            fn ($r) => response()->json(['ok' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_string_tolerance_still_enforced(): void
    {
        config()->set('wlcm.webhook.tolerance_seconds', '300');
        Carbon::setTestNow('2026-07-25 12:00:00');
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->dataStampedAt((string) now()->subSeconds(301)->getTimestampMs());

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Stale webhook timestamp', $e->getMessage());
        }
    }

    public function test_missing_tolerance_key_defaults_closed(): void
    {
        $webhook = config('wlcm.webhook');
        unset($webhook['tolerance_seconds']);
        config()->set('wlcm.webhook', $webhook);
        $this->assertFalse(config()->has('wlcm.webhook.tolerance_seconds'));

        Carbon::setTestNow('2026-07-25 12:00:00');
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->dataStampedAt((string) now()->subSeconds(301)->getTimestampMs());

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Stale webhook timestamp', $e->getMessage());
        }
    }

    public function test_invalid_tolerance_throws(): void
    {
        config()->set('wlcm.webhook.tolerance_seconds', true);
        Carbon::setTestNow('2026-07-25 12:00:00');
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $request = $this->request($this->dataStampedAt((string) now()->subSeconds(60)->getTimestampMs()));

        $this->expectException(MissingConfigurationException::class);
        $middleware->handle($request, fn ($r) => response()->json());
    }

    public function test_config_ships_tolerance_default(): void
    {
        $config = require dirname(__DIR__, 3).'/config/wlcm.php';

        $this->assertSame(300, $config['webhook']['tolerance_seconds']);
    }

    private function subscriptionData(): array
    {
        $data = [
            'event' => 'subscription.activated', 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        $data['signature'] = hash_hmac('sha256', 'order-1001:subscription.activated:1745740800000', 'whsec_test');

        return $data;
    }

    public function test_subscription_payload_verified_by_event_base(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $request = $this->request($this->subscriptionData());

        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $request->attributes->get('wlcm_payload');
        $this->assertInstanceOf(SubscriptionWebhookPayload::class, $payload);
        $this->assertSame(8, $payload->subscriptionId);
    }

    public function test_subscription_tampered_signature_403(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);
        $data = $this->subscriptionData();
        $data['external_order_id'] = 'order-9999';

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_subscription_missing_fields_422(): void
    {
        $middleware = $this->app->make(VerifyWebhookSignature::class);

        try {
            $middleware->handle($this->request(['event' => 'subscription.activated']), fn ($r) => response()->json());
            $this->fail('expected 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_subscription_stale_timestamp_403_when_tolerance_set(): void
    {
        config()->set('wlcm.webhook.tolerance_seconds', 300);
        Carbon::setTestNow('2026-07-25 12:00:00');
        $middleware = $this->app->make(VerifyWebhookSignature::class);

        $data = $this->subscriptionData();
        $data['timestamp'] = (string) now()->subSeconds(301)->getTimestampMs();
        $data['signature'] = hash_hmac('sha256', "order-1001:subscription.activated:{$data['timestamp']}", 'whsec_test');

        try {
            $middleware->handle($this->request($data), fn ($r) => response()->json());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Stale webhook timestamp', $e->getMessage());
        }
    }
}
