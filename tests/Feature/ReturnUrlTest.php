<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class ReturnUrlTest extends TestCase
{
    private function fakeCheckout(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1, 'external_id' => null, 'state' => 1,
            'checkout_url' => 'https://pay.test/1', 'message' => null,
            'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
        ], 200)]);
    }

    private function sentReturnUrl(): ?string
    {
        $found = null;

        Http::assertSent(function (Request $r) use (&$found): bool {
            $body = json_decode($r->body(), true);
            $found = is_array($body) ? ($body['return_url'] ?? null) : null;

            return true;
        });

        return is_string($found) ? $found : null;
    }

    public function test_the_explicit_argument_wins(): void
    {
        config()->set('wlcm.checkout.return_url', 'https://config.test/back');
        $this->fakeCheckout();

        Wlcm::partner()->checkout()->create(amountTiyin: 1000, returnUrl: 'https://explicit.test/back');

        $this->assertSame('https://explicit.test/back', $this->sentReturnUrl());
    }

    public function test_the_global_config_is_used_when_no_argument_is_given(): void
    {
        config()->set('wlcm.checkout.return_url', 'https://config.test/back');
        $this->fakeCheckout();

        Wlcm::partner()->checkout()->create(amountTiyin: 1000);

        $this->assertSame('https://config.test/back', $this->sentReturnUrl());
    }

    public function test_a_per_connection_value_outranks_the_global_one(): void
    {
        config()->set('wlcm.checkout.return_url', 'https://global.test/back');
        config()->set('wlcm.connections.main.checkout.return_url', 'https://main.test/back');
        $this->fakeCheckout();

        Wlcm::partner()->checkout()->create(amountTiyin: 1000);

        $this->assertSame('https://main.test/back', $this->sentReturnUrl());
    }

    public function test_a_dotted_connection_name_still_finds_its_own_value(): void
    {
        // connections['shop.uz'] read as a config PATH would be connections[shop][uz]
        // and silently find nothing — the bug class fix 7e49c91 addressed.
        config()->set('wlcm.connections', [
            'shop.uz' => [
                'partner' => [
                    'base_url' => 'https://api.wlcm.test/api/v1',
                    'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => 'w',
                ],
                'checkout' => ['return_url' => 'https://dotted.test/back'],
            ],
        ]);
        $this->fakeCheckout();

        Wlcm::connection('shop.uz')->partner()->checkout()->create(amountTiyin: 1000);

        $this->assertSame('https://dotted.test/back', $this->sentReturnUrl());
    }

    public function test_nothing_configured_omits_the_key_entirely(): void
    {
        config()->set('wlcm.checkout.return_url', null);
        $this->fakeCheckout();

        Wlcm::partner()->checkout()->create(amountTiyin: 1000);

        // Omission lets WLCM apply its own default. An empty string is accepted by the
        // server and means the payer never comes back — a different thing entirely.
        Http::assertSent(fn (Request $r): bool => ! str_contains($r->body(), 'return_url'));
    }

    public function test_a_registered_route_name_is_resolved(): void
    {
        Route::get('/payment/done', fn () => 'ok')->name('payment.return');
        // Laravel builds the name lookup once; a route registered mid-test is invisible
        // to Route::has() without this. A real app registers at boot, so this is a test
        // artifact rather than a behaviour the package has to handle.
        Route::getRoutes()->refreshNameLookups();
        config()->set('wlcm.checkout.return_url', 'payment.return');
        $this->fakeCheckout();

        Wlcm::partner()->checkout()->create(amountTiyin: 1000);

        $this->assertStringContainsString('/payment/done', (string) $this->sentReturnUrl());
    }

    public function test_a_url_is_never_mistaken_for_a_route_name(): void
    {
        Route::get('/payment/done', fn () => 'ok')->name('https://real.test/back');
        Route::getRoutes()->refreshNameLookups();
        config()->set('wlcm.checkout.return_url', 'https://real.test/back');
        $this->fakeCheckout();

        Wlcm::partner()->checkout()->create(amountTiyin: 1000);

        // Anything carrying :// is a url, whatever routes happen to be named.
        $this->assertSame('https://real.test/back', $this->sentReturnUrl());
    }

    public function test_an_unregistered_name_is_passed_through_rather_than_throwing(): void
    {
        config()->set('wlcm.checkout.return_url', 'no.such.route');
        $this->fakeCheckout();

        // A merchant whose route is defined in another context must not have their
        // checkout die over it.
        Wlcm::partner()->checkout()->create(amountTiyin: 1000);

        $this->assertSame('no.such.route', $this->sentReturnUrl());
    }

    public function test_generate_link_uses_the_same_resolution(): void
    {
        config()->set('wlcm.checkout.return_url', 'https://config.test/back');
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1, 'external_id' => null, 'checkout_url' => 'https://pay.test/1',
        ], 200)]);

        Wlcm::partner()->orders()->generateLink(provider: 'payme', returnUrl: '', orderId: 1);

        $this->assertSame('https://config.test/back', $this->sentReturnUrl());
    }
}
