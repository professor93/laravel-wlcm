<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Call once per test: first call's stubs, including catch-all, take
 * precedence over any later Http::fake()/Wlcm::fake() call.
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
            '*/integrations/orders' => Http::response([
                'order_id' => 1, 'external_id' => null, 'amount' => 100000, 'status' => 'created',
            ]),
            '*/integrations/generate/link' => Http::response([
                'order_id' => 1, 'external_id' => null, 'checkout_url' => 'https://checkout.fake/wlcm',
            ]),
            '*/partners/me' => Http::response([
                'id' => 1, 'name' => 'Fake Partner', 'company_name' => 'Fake Partner LLC',
                'receipt_item_name' => null, 'email' => 'partner@fake.test', 'phone' => null,
                'partner_type' => '', 'status' => '1', 'is_active' => true,
                'created_at' => '2026-01-01T00:00:00Z', 'sub_partners' => [],
                'cancel_payment_enabled' => false, 'show_phone_number' => false,
            ]),
            '*' => Http::response(['detail' => 'wlcm stub missing for this endpoint'], 500),
        ]);
    }

    // Fresh installs have no WLCM env vars; without these client dies on
    // MissingConfigurationException before any stub is consulted.
    private function fillMissingCredentials(): void
    {
        $default = Config::get('wlcm.default', 'main');
        /** @var WlcmJsonScalar $default */
        $connection = (string) $default;

        // Read and write by array key: a dotted connection name in a config path
        // would fill a block nobody reads.
        $connections = Config::get('wlcm.connections');
        $connections = is_array($connections) ? $connections : [];
        $block = $connections[$connection] ?? null;
        $block = is_array($block) ? $block : [];
        $partner = $block['partner'] ?? null;
        $partner = is_array($partner) ? $partner : [];

        foreach (self::DUMMY_CREDENTIALS as $key => $value) {
            $current = $partner[$key] ?? null;

            if (! is_string($current) || $current === '') {
                $partner[$key] = $value;
            }
        }

        $block['partner'] = $partner;
        $connections[$connection] = $block;

        Config::set('wlcm.connections', $connections);
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
        $this->assertPostedTo('/integrations/checkout', $filter);
    }

    /** @param (callable(array<string, mixed>): bool)|null $filter */
    public function assertOrderCreated(?callable $filter = null): void
    {
        $this->assertPostedTo('/integrations/orders', $filter);
    }

    /** @param (callable(array<string, mixed>): bool)|null $filter */
    public function assertLinkGenerated(?callable $filter = null): void
    {
        $this->assertPostedTo('/integrations/generate/link', $filter);
    }

    /** @param (callable(array<string, mixed>): bool)|null $filter */
    private function assertPostedTo(string $path, ?callable $filter): void
    {
        Http::assertSent(function (Request $request) use ($path, $filter): bool {
            // Path-suffix, not a substring: /orders/{id}/status would otherwise satisfy
            // an assertion about /integrations/orders.
            if (! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $path)) {
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
