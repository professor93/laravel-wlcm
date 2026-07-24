<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Call once per test: the first call's stubs, including the catch-all,
 * take precedence over any later Http::fake()/Wlcm::fake() calls.
 */
final class WlcmFake
{
    /** @param array<string, array<string, mixed>|PromiseInterface> $responses */
    public function __construct(array $responses = [])
    {
        $prepared = [];

        foreach ($responses as $pattern => $response) {
            $prepared[$pattern] = is_array($response) ? Http::response($response) : $response;
        }

        // User patterns first: Laravel matches stubs in registration order.
        Http::fake($prepared + [
            '*/integrations/checkout' => Http::response([
                'order_id' => 1, 'external_id' => null, 'state' => 1,
                'checkout_url' => 'https://checkout.fake/wlcm', 'message' => null,
                'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
            ], 200),
            '*/integrations/payment/card/confirm' => Http::response([
                'success' => true, 'order_id' => 1, 'message' => null,
            ]),
            '*/partners/me' => Http::response([
                'id' => 1, 'name' => 'Fake Partner', 'company_name' => 'Fake Partner LLC',
                'receipt_item_name' => null, 'email' => 'partner@fake.test', 'phone' => null,
                'partner_type' => '', 'status' => '1', 'is_active' => true,
                'created_at' => '2026-01-01T00:00:00Z', 'sub_partners' => [],
                'cancel_payment_enabled' => false, 'show_phone_number' => false,
            ]),
            '*' => Http::response(['result' => null]),
        ]);
    }

    public function assertSent(callable $filter): void
    {
        Http::assertSent($filter);
    }

    public function assertNothingSent(): void
    {
        Http::assertNothingSent();
    }

    /** @param (callable(array<string, mixed>): bool)|null $filter */
    public function assertCheckoutCreated(?callable $filter = null): void
    {
        Http::assertSent(function (Request $request) use ($filter): bool {
            if (! str_contains($request->url(), '/integrations/checkout')) {
                return false;
            }

            if ($filter === null) {
                return true;
            }

            $payload = json_decode($request->body(), true);

            if (! is_array($payload)) {
                return $filter([]);
            }

            /** @var array<string, mixed> $payload */
            return $filter($payload);
        });
    }
}
