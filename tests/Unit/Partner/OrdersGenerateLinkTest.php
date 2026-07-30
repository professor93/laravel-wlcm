<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\PaymentProvider;
use Uzbek\Wlcm\Exceptions\InvalidOrderReferenceException;
use Uzbek\Wlcm\Exceptions\NotFoundException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class OrdersGenerateLinkTest extends TestCase
{
    /**
     * Live sandbox response, verbatim (order 661, probed 2026-07-29).
     *
     * @return array<string, string|int>
     */
    private function liveBody(): array
    {
        return [
            'order_id' => 661,
            'external_id' => 'probe3-20260729-205040-589f3f',
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/661',
        ];
    }

    public function test_it_links_by_order_id(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response($this->liveBody(), 200)]);

        $link = Wlcm::partner()->orders()->generateLink(
            provider: PaymentProvider::Payme,
            returnUrl: 'https://shop.test/return',
            orderId: 661,
        );

        $this->assertSame(661, $link->orderId);
        $this->assertSame('https://sandbox.wlcm.uz/pay/661', $link->checkoutUrl);

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/integrations/generate/link')
            && $r->body() === '{"order_id":661,"payment_provider":"payme","return_url":"https://shop.test/return"}');
    }

    public function test_it_links_by_external_id(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response($this->liveBody(), 200)]);

        Wlcm::partner()->orders()->generateLink(
            provider: 'mock_payme',
            returnUrl: 'https://shop.test/return',
            externalId: 'ORD-7',
        );

        // Exactly one identifier on the wire — the server rejects both together.
        Http::assertSent(fn (Request $r): bool => $r->body() === '{"external_id":"ORD-7","payment_provider":"mock_payme","return_url":"https://shop.test/return"}');
    }

    public function test_neither_identifier_is_refused_before_the_wire(): void
    {
        Http::fake();

        $this->expectException(InvalidOrderReferenceException::class);

        try {
            Wlcm::partner()->orders()->generateLink(provider: 'payme', returnUrl: 'https://shop.test/return');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_both_identifiers_are_refused_before_the_wire(): void
    {
        Http::fake();

        $this->expectException(InvalidOrderReferenceException::class);

        try {
            Wlcm::partner()->orders()->generateLink(
                provider: 'payme',
                returnUrl: 'https://shop.test/return',
                orderId: 661,
                externalId: 'ORD-7',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_an_empty_return_url_is_passed_through(): void
    {
        // The server accepts it with 200. The package is a thin client and does not
        // refuse what the platform allows.
        Http::fake(['api.wlcm.test/*' => Http::response($this->liveBody(), 200)]);

        Wlcm::partner()->orders()->generateLink(provider: 'payme', returnUrl: '', orderId: 661);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '"return_url":""'));
    }

    public function test_an_unsupported_provider_is_a_validation_failure(): void
    {
        // Live 400 body, verbatim.
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'Provider is not supported'], 400)]);

        try {
            Wlcm::partner()->orders()->generateLink(provider: 'not_a_provider', returnUrl: 'https://shop.test/r', orderId: 661);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Provider is not supported', $e->getMessage());
            $this->assertNull($e->errorCode);
        }
    }

    public function test_an_unconfigured_provider_is_a_validation_failure(): void
    {
        // Live 400 body, verbatim — distinct from "not supported": the provider exists
        // but this partner has it switched off.
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'Provider is not configured'], 400)]);

        try {
            Wlcm::partner()->orders()->generateLink(provider: PaymentProvider::Uzum, returnUrl: 'https://shop.test/r', orderId: 661);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Provider is not configured', $e->getMessage());
            $this->assertNull($e->errorCode);
        }
    }

    public function test_a_missing_order_is_a_not_found(): void
    {
        // Live 404 body, verbatim. Same for both identifier forms.
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'Order not found'], 404)]);

        try {
            Wlcm::partner()->orders()->generateLink(provider: 'payme', returnUrl: 'https://shop.test/r', orderId: 999999999);
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('Order not found', $e->getMessage());
            $this->assertNull($e->errorCode);
        }
    }
}
