<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Exceptions\ServerException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class FakeTest extends TestCase
{
    public function test_fake_checkout_returns_default_response(): void
    {
        Wlcm::fake();

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $this->assertSame('https://checkout.fake/wlcm', $r->checkoutUrl);
    }

    public function test_fake_works_without_any_credential_config(): void
    {
        config()->set('wlcm.connections.main.partner', [
            'base_url' => null, 'api_key' => null, 'api_secret' => null, 'webhook_secret' => null,
        ]);

        $fake = Wlcm::fake();

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $this->assertSame('https://checkout.fake/wlcm', $r->checkoutUrl);
        $fake->assertSent(fn (Request $request): bool => $request->url() === 'https://wlcm.fake/api/v1/integrations/checkout');
    }

    public function test_fake_never_clobbers_configured_credentials(): void
    {
        $fake = Wlcm::fake();

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $this->assertSame('https://api.wlcm.test/api/v1', config('wlcm.connections.main.partner.base_url'));
        $this->assertSame('pk_test_key', config('wlcm.connections.main.partner.api_key'));
        $this->assertSame('sk_test_secret', config('wlcm.connections.main.partner.api_secret'));
        $this->assertSame('whsec_test', config('wlcm.connections.main.partner.webhook_secret'));
        $fake->assertSent(fn (Request $request): bool => $request->url() === 'https://api.wlcm.test/api/v1/integrations/checkout');
    }

    public function test_fake_fills_credentials_for_a_non_string_default_connection(): void
    {
        config()->set('wlcm.default', 0);
        config()->set('wlcm.connections.0.partner', [
            'base_url' => null, 'api_key' => null, 'api_secret' => null, 'webhook_secret' => null,
        ]);

        $fake = Wlcm::fake();

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $fake->assertSent(fn (Request $request): bool => $request->url() === 'https://wlcm.fake/api/v1/integrations/checkout');
    }

    public function test_every_default_stub_is_reachable(): void
    {
        Wlcm::fake();

        $this->assertSame('Fake Partner', Wlcm::partner()->partners()->me()->name);
        $this->assertTrue(Wlcm::partner()->payments()->confirmOtp('tx-1', 'cid-1', '111111')->success);
    }

    public function test_first_fake_wins_over_a_later_http_fake(): void
    {
        Wlcm::fake();
        Http::fake([
            '*/integrations/checkout' => Http::response(['order_id' => 99, 'state' => 0, 'checkout_url' => 'https://late.test'], 200),
            '*/subscriptions/55/charge' => Http::response(['status' => 'success'], 200),
        ]);

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $this->assertSame('https://checkout.fake/wlcm', $r->checkoutUrl);

        $this->expectException(ServerException::class);

        Wlcm::partner()->subscriptions()->charge(55, 499000, 7);
    }

    public function test_first_fake_wins_over_a_later_wlcm_fake(): void
    {
        Wlcm::fake();
        Wlcm::fake(['*/integrations/checkout' => ['order_id' => 99, 'state' => 0, 'checkout_url' => 'https://late.test']]);

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $this->assertSame('https://checkout.fake/wlcm', $r->checkoutUrl);
    }

    public function test_fake_stubs_every_outbound_call_not_just_wlcm(): void
    {
        Wlcm::fake();

        $response = Http::get('https://unrelated.test/anything');

        $this->assertSame(500, $response->status());
        $this->assertSame(['detail' => 'wlcm stub missing for this endpoint'], $response->json());
    }

    public function test_the_filled_webhook_secret_is_the_published_constant(): void
    {
        config()->set('wlcm.connections.main.partner', [
            'base_url' => null, 'api_key' => null, 'api_secret' => null, 'webhook_secret' => null,
        ]);
        Wlcm::fake();

        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '10.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", 'whsec_fake');

        $this->postJson('/wlcm/webhook', $data)->assertOk();
    }

    public function test_fake_after_the_connection_is_cached_leaves_it_on_the_old_config(): void
    {
        config()->set('wlcm.connections.main.partner.base_url', '');

        Wlcm::connection();
        Wlcm::fake();

        $this->assertSame('https://wlcm.fake/api/v1', config('wlcm.connections.main.partner.base_url'));
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.connections.main.partner.base_url');

        Wlcm::partner();
    }

    public function test_unstubbed_endpoint_throws_naming_the_path(): void
    {
        Wlcm::fake();

        try {
            Wlcm::partner()->subscriptions()->charge(55, 499000, 7);
            $this->fail('unstubbed call should not succeed');
        } catch (ServerException $e) {
            $this->assertSame(500, $e->responseStatus);
            $this->assertStringContainsString('/subscriptions/55/charge', (string) $e->requestPath);
            $this->assertStringContainsString('wlcm stub missing', $e->getMessage());
        }
    }

    public function test_assert_checkout_created_fails_when_filter_rejects(): void
    {
        $fake = Wlcm::fake();

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-7');

        $this->expectException(AssertionFailedError::class);

        $fake->assertCheckoutCreated(fn (array $payload): bool => $payload['external_id'] === 'ORD-8');
    }

    public function test_assert_nothing_sent_fails_after_a_call(): void
    {
        $fake = Wlcm::fake();

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $this->expectException(AssertionFailedError::class);

        $fake->assertNothingSent();
    }

    public function test_fake_records_and_asserts(): void
    {
        $fake = Wlcm::fake();

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-7');

        $fake->assertCheckoutCreated(fn (array $payload): bool => $payload['external_id'] === 'ORD-7'
            && $payload['amount'] === 120000);
    }

    public function test_fake_custom_response(): void
    {
        Wlcm::fake([
            '*/integrations/checkout' => ['order_id' => 42, 'state' => 0, 'checkout_url' => 'https://custom.test'],
        ]);

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 100, returnUrl: 'https://x.test');

        $this->assertSame(42, $r->orderId);
        $this->assertSame('https://custom.test', $r->checkoutUrl);
    }

    public function test_assert_nothing_sent(): void
    {
        $fake = Wlcm::fake();

        $fake->assertNothingSent();
    }
}
