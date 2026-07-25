<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Call once per test: the first call's stubs, including the catch-all,
 * take precedence over any later Http::fake()/Wlcm::fake() calls.
 */
final class WlcmFake
{
    private const array DUMMY_CREDENTIALS = [
        'base_url' => 'https://wlcm.fake/api/v1',
        'api_key' => 'fake-key',
        'api_secret' => 'fake-secret',
        'webhook_secret' => 'whsec_fake',
    ];

    /** @param array<string, array<string, mixed>|PromiseInterface> $responses */
    public function __construct(array $responses = [])
    {
        $this->fillMissingCredentials();

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
            '*' => Http::response(['error' => 'wlcm stub missing for this endpoint'], 500),
        ]);
    }

    // Fresh installs have no WLCM env vars; without these the client dies on
    // MissingConfigurationException before a stub is ever consulted.
    private function fillMissingCredentials(): void
    {
        $default = Config::get('wlcm.default');
        $connection = is_string($default) && $default !== '' ? $default : 'main';

        foreach (self::DUMMY_CREDENTIALS as $key => $value) {
            $path = "wlcm.connections.{$connection}.partner.{$key}";
            $current = Config::get($path);

            if (! is_string($current) || $current === '') {
                Config::set($path, $value);
            }
        }
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
