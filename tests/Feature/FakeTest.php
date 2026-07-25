<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Client\Request;
use PHPUnit\Framework\AssertionFailedError;
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

        $this->assertSame('pk_test_key', config('wlcm.connections.main.partner.api_key'));
        $fake->assertSent(fn (Request $request): bool => $request->url() === 'https://api.wlcm.test/api/v1/integrations/checkout');
    }

    public function test_every_default_stub_is_reachable(): void
    {
        Wlcm::fake();

        $this->assertSame('Fake Partner', Wlcm::partner()->partners()->me()->name);
        $this->assertTrue(Wlcm::partner()->payments()->confirmOtp('tx-1', 'cid-1', '111111')->success);
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
