<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class DottedConnectionTest extends TestCase
{
    private const string NAME = 'shop.uz';

    private const string SECRET = 'whsec_shop_uz';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var array<string, mixed> $connections */
        $connections = $app['config']->get('wlcm.connections');
        $connections[self::NAME] = ['partner' => [
            'base_url' => 'https://shop.wlcm.test/api/v1',
            'api_key' => 'pk_shop',
            'api_secret' => 'sk_shop',
            'webhook_secret' => self::SECRET,
        ]];
        $app['config']->set('wlcm.connections', $connections);
    }

    /** @return array<string, string> */
    private function webhookData(string $secret): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", $secret);

        return $data;
    }

    private function header(Request $request, string $name): string
    {
        $value = $request->header($name)[0] ?? null;

        return is_string($value) ? $value : '';
    }

    public function test_outbound_calls_use_the_dotted_connections_own_credentials(): void
    {
        Http::fake(['shop.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-9', 'state' => 0,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);

        Wlcm::connection(self::NAME)->partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9',
        );

        Http::assertSent(function (Request $request): bool {
            $expected = hash_hmac('sha256', implode("\n", [
                'POST',
                '/api/v1/integrations/checkout',
                $this->header($request, 'X-Timestamp'),
                hash('sha256', $request->body()),
            ]), 'sk_shop');

            return $request->url() === 'https://shop.wlcm.test/api/v1/integrations/checkout'
                && $this->header($request, 'X-API-Key') === 'pk_shop'
                && $this->header($request, 'X-Signature') === $expected;
        });
    }

    public function test_a_signed_delivery_to_a_dotted_connection_is_accepted(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook/'.self::NAME, $this->webhookData(self::SECRET))->assertOk();

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_a_delivery_signed_with_another_connections_secret_is_rejected(): void
    {
        $this->postJson('/wlcm/webhook/'.self::NAME, $this->webhookData('whsec_test'))->assertForbidden();
    }

    public function test_the_plain_connection_keeps_its_own_secret(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->webhookData('whsec_test'))->assertOk();
        $this->postJson('/wlcm/webhook', $this->webhookData(self::SECRET))->assertForbidden();

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_an_unknown_dotted_connection_still_404s_at_the_route(): void
    {
        $this->postJson('/wlcm/webhook/shop.kz', $this->webhookData(self::SECRET))->assertNotFound();
    }

    public function test_an_unknown_dotted_connection_still_names_the_same_config_key(): void
    {
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('Missing configuration: wlcm.connections.shop.kz');

        Wlcm::connection('shop.kz');
    }

    public function test_the_fake_fills_credentials_for_a_dotted_default_connection(): void
    {
        config()->set('wlcm.default', self::NAME);
        config()->set('wlcm.connections', [self::NAME => ['partner' => [
            'base_url' => null, 'api_key' => null, 'api_secret' => null, 'webhook_secret' => null,
        ]]]);

        $fake = Wlcm::fake();

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $fake->assertSent(fn (Request $request): bool => $request->url() === 'https://wlcm.fake/api/v1/integrations/checkout');
    }
}
