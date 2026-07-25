<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\WebhookInfo;
use Uzbek\Wlcm\Partner\RequestSigner;
use Uzbek\Wlcm\Tests\TestCase;

final class WebhooksResourceTest extends TestCase
{
    public function test_all_returns_empty_list(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([], 200)]);

        $this->assertSame([], Wlcm::partner()->webhooks()->all());

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/partners/me/webhooks'));
    }

    public function test_all_parses_webhooks(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            ['id' => 7, 'url' => 'https://app.test/hook', 'secret_key' => 'whsec_x', 'is_active' => true, 'created_at' => '2026-07-24T10:00:00Z'],
        ], 200)]);

        $hooks = Wlcm::partner()->webhooks()->all();

        $this->assertCount(1, $hooks);
        $this->assertSame(7, $hooks[0]->id);
        $this->assertSame('https://app.test/hook', $hooks[0]->url);
        $this->assertSame('whsec_x', $hooks[0]->secretKey);
        $this->assertTrue($hooks[0]->isActive);
    }

    public function test_all_survives_a_null_results_envelope(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'count' => 0, 'next' => null, 'previous' => null, 'results' => null,
        ], 200)]);

        $this->assertSame([], Wlcm::partner()->webhooks()->all());
    }

    public function test_all_survives_a_detail_string_body(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'Not found.'], 200)]);

        $this->assertSame([], Wlcm::partner()->webhooks()->all());
    }

    public function test_all_drops_non_array_rows(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'oops',
            ['id' => 7, 'url' => 'https://app.test/hook', 'is_active' => true],
        ], 200)]);

        $hooks = Wlcm::partner()->webhooks()->all();

        $this->assertCount(1, $hooks);
        $this->assertSame(7, $hooks[0]->id);
    }

    public function test_create_sends_url_and_secret(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'id' => 7, 'url' => 'https://app.test/hook', 'secret_key' => 'whsec_x',
        ], 200)]);

        $hook = Wlcm::partner()->webhooks()->create('https://app.test/hook', 'whsec_x');

        $this->assertSame(7, $hook->id);
        $this->assertNull($hook->isActive);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->body() === '{"secret_key":"whsec_x","url":"https://app.test/hook"}');
    }

    public function test_update_sends_signed_patch(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['id' => 7, 'url' => 'https://new.test/hook'], 200)]);

        $hook = Wlcm::partner()->webhooks()->update(7, ['url' => 'https://new.test/hook']);

        $this->assertSame('https://new.test/hook', $hook->url);
        Http::assertSent(function (Request $r): bool {
            $timestamp = $r->header('X-Timestamp')[0];
            $this->assertIsString($timestamp);
            $signer = new RequestSigner('sk_test_secret');
            $expected = $signer->sign('PATCH', $r->url(), $timestamp, $r->body());

            return $r->method() === 'PATCH'
                && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/partners/me/webhooks/7')
                && $r->header('X-Signature')[0] === $expected;
        });
    }

    public function test_delete_sends_signed_empty_body(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([], 200)]);

        Wlcm::partner()->webhooks()->delete(7);

        Http::assertSent(function (Request $r): bool {
            $timestamp = $r->header('X-Timestamp')[0];
            $this->assertIsString($timestamp);
            $signer = new RequestSigner('sk_test_secret');
            $expected = $signer->sign('DELETE', $r->url(), $timestamp, '');

            return $r->method() === 'DELETE'
                && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/partners/me/webhooks/7')
                && $r->header('X-Signature')[0] === $expected;
        });
    }

    public function test_events_history_unwraps_pagination(): void
    {
        // Live shape: paginated envelope; rows include delivery status and retry data.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'count' => 1, 'next' => null, 'previous' => null,
            'results' => [
                ['id' => 1, 'webhook_id' => 7, 'event_type' => 'payment.success',
                    'payload' => ['order_id' => 414], 'status' => 'failed', 'attempts' => 1,
                    'response_code' => 404, 'response_body' => 'Not Found',
                    'error_message' => null, 'next_retry_at' => null,
                    'created_at' => '2026-07-24T22:00:00Z'],
            ],
        ], 200)]);

        $events = Wlcm::partner()->webhooks()->events(7);

        $this->assertCount(1, $events);
        $this->assertSame('payment.success', $events[0]['event_type']);
        $this->assertSame(1, $events[0]['attempts']);
        $this->assertNull($events[0]['next_retry_at']);
        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/partners/me/webhooks/7/events'));
    }

    public function test_events_history_tolerates_bare_list(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            ['id' => 1, 'event_type' => 'payment.success'],
        ], 200)]);

        $events = Wlcm::partner()->webhooks()->events(7);

        $this->assertCount(1, $events);
        $this->assertSame('payment.success', $events[0]['event_type']);
    }

    public function test_events_history_survives_a_null_results_envelope(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'count' => 0, 'next' => null, 'previous' => null, 'results' => null,
        ], 200)]);

        $this->assertSame([], Wlcm::partner()->webhooks()->events(7));
    }

    public function test_events_history_survives_a_detail_string_body(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'Not found.'], 200)]);

        $this->assertSame([], Wlcm::partner()->webhooks()->events(7));
    }

    public function test_events_history_drops_non_array_rows(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'oops',
            ['id' => 1, 'event_type' => 'payment.success'],
        ], 200)]);

        $events = Wlcm::partner()->webhooks()->events(7);

        $this->assertCount(1, $events);
        $this->assertSame('payment.success', $events[0]['event_type']);
    }

    public function test_webhook_info_full_shape(): void
    {
        // Live object shape after PATCH: includes events list and delivery telemetry.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'id' => 7, 'url' => 'https://app.test/hook', 'secret_key' => 'whsec_x',
            'events' => ['payment.success'], 'is_active' => true,
            'last_triggered_at' => '2026-07-24T22:00:00Z', 'last_status_code' => 200,
            'created_at' => '2026-07-24T10:00:00Z',
        ], 200)]);

        $hook = Wlcm::partner()->webhooks()->update(7, ['url' => 'https://app.test/hook']);

        $this->assertSame(['payment.success'], $hook->events);
        $this->assertSame('2026-07-24T22:00:00Z', $hook->lastTriggeredAt);
        $this->assertSame(200, $hook->lastStatusCode);
    }

    public function test_all_unwraps_pagination(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'count' => 1, 'next' => null, 'previous' => null,
            'results' => [
                ['id' => 7, 'url' => 'https://app.test/hook', 'is_active' => true],
            ],
        ], 200)]);

        $hooks = Wlcm::partner()->webhooks()->all();

        $this->assertCount(1, $hooks);
        $this->assertSame(7, $hooks[0]->id);
        $this->assertSame('https://app.test/hook', $hooks[0]->url);
    }

    /** GREEN-lock: WebhookInfo already survives a bare {}. */
    public function test_webhook_info_tolerates_empty_server_shape(): void
    {
        $hook = WebhookInfo::fromArray([]);

        $this->assertSame(0, $hook->id);
        $this->assertSame('', $hook->url);
        $this->assertNull($hook->secretKey);
        $this->assertNull($hook->isActive);
        $this->assertNull($hook->events);
        $this->assertNull($hook->lastTriggeredAt);
        $this->assertNull($hook->lastStatusCode);
        $this->assertNull($hook->createdAt);
        $this->assertSame([], $hook->raw);
    }

    public function test_webhook_info_tolerates_wrong_types(): void
    {
        $hook = WebhookInfo::fromArray([
            'id' => ['x'], 'url' => ['x'], 'secret_key' => ['x'],
            'events' => 'not-a-list', 'last_status_code' => ['x'], 'created_at' => ['x'],
        ]);

        $this->assertSame(0, $hook->id);
        $this->assertSame('', $hook->url);
        $this->assertNull($hook->secretKey);
        $this->assertNull($hook->events);
        $this->assertNull($hook->lastStatusCode);
        $this->assertNull($hook->createdAt);
    }
}
