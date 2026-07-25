<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\PaymentProvider;
use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class CheckoutTest extends TestCase
{
    public function test_redirect_checkout(): void
    {
        // Live sandbox response, verbatim: HTTP 200, created order state = 1, all keys present.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'external_id' => 'probe-mock-163350', 'state' => 1,
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/410', 'message' => null,
            'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
        ], 200)]);

        $r = Wlcm::partner()->checkout()->create(
            amount: 120000,
            returnUrl: 'https://merchant.example.com/payment/return',
            externalId: 'probe-mock-163350',
            provider: PaymentProvider::Payme,
        );

        $this->assertSame(410, $r->orderId);
        $this->assertSame(PaymentState::Initiating, $r->stateEnum());
        $this->assertSame('https://sandbox.wlcm.uz/pay/410', $r->checkoutUrl);
        $this->assertNull($r->transactionId);

        Http::assertSent(fn (Request $req): bool => str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/integrations/checkout')
            && $req->body() === '{"amount":120000,"external_id":"probe-mock-163350","payment_provider":"payme","return_url":"https://merchant.example.com/payment/return"}');
    }

    public function test_card_checkout_and_otp_confirm(): void
    {
        Http::fake([
            'api.wlcm.test/*/checkout' => Http::response([
                'order_id' => 1219, 'external_id' => 't-1', 'state' => 1,
                'checkout_url' => null, 'message' => null,
                'transaction_id' => '6eb241ad-eab8-418f-9a66-55db127bec30',
                'otp_sent_phone' => '**********02',
                'cid' => '1d7d3fb6-70f6-4d4e-8c85-aadf2d92873e',
            ], 200),
            'api.wlcm.test/*/confirm' => Http::response([
                'success' => true, 'order_id' => 1219, 'message' => 'Already paid',
            ], 200),
        ]);

        $r = Wlcm::partner()->checkout()->create(
            amount: 120000,
            externalId: 't-1',
            provider: 'card',
            cardNumber: '9860990000000000',
            expireDate: '3003',
        );

        $this->assertSame(PaymentState::Initiating, $r->stateEnum());
        $this->assertNotNull($r->transactionId);

        $c = Wlcm::partner()->payments()->confirmOtp($r->transactionId, $r->cid, '111111');
        $this->assertTrue($c->success);
        $this->assertTrue($c->alreadyPaid());
    }

    public function test_missing_state_is_null_not_created(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1, 'external_id' => null,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 200)]);

        $r = Wlcm::partner()->checkout()->create(amount: 100, returnUrl: 'https://x.test');

        $this->assertNull($r->state);
        $this->assertNull($r->stateEnum());
    }

    public function test_non_numeric_state_is_null(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1, 'external_id' => null, 'state' => 'created',
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 200)]);

        $r = Wlcm::partner()->checkout()->create(amount: 100, returnUrl: 'https://x.test');

        $this->assertNull($r->state);
        $this->assertNull($r->stateEnum());
    }

    public function test_unknown_state_survives(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1, 'external_id' => null, 'state' => 99,
            'checkout_url' => null, 'message' => null,
        ], 200)]);

        $r = Wlcm::partner()->checkout()->create(amount: 100, returnUrl: 'https://x.test');
        $this->assertSame(99, $r->state);
        $this->assertNull($r->stateEnum());
    }
}
