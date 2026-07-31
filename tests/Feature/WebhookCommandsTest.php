<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Tests\TestCase;

final class WebhookCommandsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function webhook(int $id, string $url, bool $active = true): array
    {
        return [
            'id' => $id, 'url' => $url, 'secret_key' => 'whsec_x', 'is_active' => $active,
            'events' => ['payment.succeeded'], 'last_triggered_at' => '2026-08-01T10:00:00Z',
            'last_status_code' => 200, 'created_at' => '2026-07-01T10:00:00Z',
        ];
    }

    /** @param array<string, mixed> ...$webhooks */
    private function fakeList(array ...$webhooks): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response($webhooks, 200)]);
    }

    public function test_list_renders_the_endpoints(): void
    {
        $this->fakeList($this->webhook(1, 'https://shop.test/wlcm/webhook'));

        $this->artisan('wlcm:webhook:list')
            ->expectsOutputToContain('https://shop.test/wlcm/webhook')
            ->assertExitCode(0);
    }

    public function test_list_json_emits_the_raw_server_objects(): void
    {
        $this->fakeList($this->webhook(1, 'https://shop.test/wlcm/webhook'));

        $this->artisan('wlcm:webhook:list', ['--json' => true])
            ->expectsOutputToContain('"secret_key"')
            ->assertExitCode(0);
    }

    public function test_active_only_keeps_endpoints_of_unknown_state(): void
    {
        // is_active null means WLCM did not say. Hiding it would misreport the list.
        Http::fake(['api.wlcm.test/*' => Http::response([
            $this->webhook(1, 'https://a.test/hook', active: false),
            ['id' => 2, 'url' => 'https://b.test/hook'],
        ], 200)]);

        $this->artisan('wlcm:webhook:list', ['--active-only' => true])
            ->expectsOutputToContain('https://b.test/hook')
            ->doesntExpectOutputToContain('https://a.test/hook')
            ->assertExitCode(0);
    }

    public function test_update_refuses_an_empty_patch(): void
    {
        Http::fake();

        $this->artisan('wlcm:webhook:update', ['id' => 1])->assertExitCode(2);

        // A no-op PATCH would report success while changing nothing.
        Http::assertNothingSent();
    }

    public function test_update_refuses_contradictory_flags(): void
    {
        Http::fake();

        $this->artisan('wlcm:webhook:update', ['id' => 1, '--active' => true, '--inactive' => true])
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_update_sends_only_what_was_asked_for(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response($this->webhook(1, 'https://new.test/hook'), 200)]);

        $this->artisan('wlcm:webhook:update', ['id' => 1, '--url' => 'https://new.test/hook', '--inactive' => true])
            ->assertExitCode(0);

        // PartnerClient::patch encodes with JSON_UNESCAPED_SLASHES, so the url is literal.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH'
            && $r->body() === '{"url":"https://new.test/hook","is_active":false}');
    }

    public function test_update_splits_an_events_csv(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response($this->webhook(1, 'https://a.test/hook'), 200)]);

        $this->artisan('wlcm:webhook:update', ['id' => 1, '--events' => 'a.b , c.d ,, e.f'])
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '["a.b","c.d","e.f"]'));
    }

    public function test_delete_refuses_this_apps_own_endpoint_without_force(): void
    {
        config()->set('app.url', 'https://shop.test');
        $this->fakeList($this->webhook(1, 'https://shop.test/wlcm/webhook'));

        $this->artisan('wlcm:webhook:delete', ['id' => 1])
            ->expectsOutputToContain("this application's own webhook endpoint")
            ->assertExitCode(2);

        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_delete_allows_the_own_endpoint_with_force(): void
    {
        config()->set('app.url', 'https://shop.test');
        Http::fake([
            'api.wlcm.test/*/webhooks/1' => Http::response([], 200),
            'api.wlcm.test/*' => Http::response([$this->webhook(1, 'https://shop.test/wlcm/webhook')], 200),
        ]);

        $this->artisan('wlcm:webhook:delete', ['id' => 1, '--force' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_delete_allows_a_foreign_endpoint(): void
    {
        config()->set('app.url', 'https://shop.test');
        Http::fake([
            'api.wlcm.test/*/webhooks/1' => Http::response([], 200),
            'api.wlcm.test/*' => Http::response([$this->webhook(1, 'https://someone-else.test/wlcm/webhook')], 200),
        ]);

        $this->artisan('wlcm:webhook:delete', ['id' => 1, '--force' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_delete_reports_an_unknown_id_as_a_usage_error(): void
    {
        $this->fakeList($this->webhook(1, 'https://a.test/hook'));

        $this->artisan('wlcm:webhook:delete', ['id' => 99])->assertExitCode(2);

        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE');
    }

    public function test_a_missing_id_in_non_interactive_mode_is_a_usage_error(): void
    {
        Http::fake();

        $this->artisan('wlcm:webhook:delete', ['--no-interaction' => true])->assertExitCode(2);
    }

    public function test_an_api_failure_is_distinguishable_from_a_usage_error(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'nope'], 500)]);

        // 1 = the server said no. 2 = the operator did. They must not be the same code.
        $this->artisan('wlcm:webhook:list')->assertExitCode(1);
    }
}
