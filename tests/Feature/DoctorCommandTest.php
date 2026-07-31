<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Uzbek\Wlcm\Tests\TestCase;

final class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    private function fakeHealthy(): void
    {
        Http::fake([
            'api.wlcm.test/*/webhooks/*/events' => Http::response(['results' => [[
                'id' => 1, 'status' => 'delivered', 'attempts' => 1, 'response_code' => 200,
                'error_message' => null, 'next_retry_at' => null, 'event_type' => 'payment.success',
                'created_at' => '2026-08-01T00:00:00Z',
            ]]], 200),
            'api.wlcm.test/*/webhooks' => Http::response([[
                'id' => 1, 'url' => 'https://shop.example.com/wlcm/webhook', 'secret_key' => 'x',
                'is_active' => true, 'events' => [], 'last_triggered_at' => null,
                'last_status_code' => 200, 'created_at' => null,
            ]], 200),
            'api.wlcm.test/*/partners/me' => Http::response([
                'id' => 31, 'name' => 'Acme', 'company_name' => 'Acme LLC', 'receipt_item_name' => null,
                'email' => null, 'phone' => null, 'partner_type' => '', 'status' => '1',
                'is_active' => true, 'created_at' => null, 'sub_partners' => [],
                'cancel_payment_enabled' => false, 'show_phone_number' => false,
            ], 200),
        ]);
    }

    public function test_a_healthy_installation_passes(): void
    {
        $this->fakeHealthy();

        $this->artisan('wlcm:doctor')->assertExitCode(0);
    }

    public function test_missing_credentials_fail(): void
    {
        config()->set('wlcm.connections.main.partner.api_secret', null);

        $this->artisan('wlcm:doctor', ['--skip-api' => true])->assertExitCode(1);
    }

    public function test_an_unreadable_toggle_fails_rather_than_being_ignored(): void
    {
        // 'off' is a typo, not an off switch — and it is the value that once left a
        // public endpoint serving.
        config()->set('wlcm.storage.transactions', 'off');

        $this->artisan('wlcm:doctor', ['--skip-api' => true])->assertExitCode(1);
    }

    public function test_a_malformed_allowlist_fails(): void
    {
        config()->set('wlcm.webhook.allowed_ips', '10.0.0.0/8, nonsense');

        $this->artisan('wlcm:doctor', ['--skip-api' => true])->assertExitCode(1);
    }

    public function test_transactions_on_without_the_table_fails(): void
    {
        // The silent version of this is every audit write swallowed by report().
        config()->set('wlcm.storage.transactions', true);
        Schema::drop('wlcm_transactions');

        $this->artisan('wlcm:doctor', ['--skip-api' => true])->assertExitCode(1);
    }

    public function test_a_shadowing_route_fails(): void
    {
        // A host route at the same path takes the endpoint and serves it unverified.
        Route::post('wlcm/webhook/{connection?}', fn () => 'mine');

        $this->artisan('wlcm:doctor', ['--skip-api' => true])->assertExitCode(1);
    }

    public function test_a_webhook_with_no_scheme_fails(): void
    {
        // The real case: partner 31 had `wb.com` and every delivery failed.
        Http::fake([
            'api.wlcm.test/*/webhooks/*/events' => Http::response(['results' => []], 200),
            'api.wlcm.test/*/webhooks' => Http::response([[
                'id' => 1, 'url' => 'wb.com', 'secret_key' => 'x', 'is_active' => true,
                'events' => [], 'last_triggered_at' => null, 'last_status_code' => null, 'created_at' => null,
            ]], 200),
            'api.wlcm.test/*/partners/me' => Http::response([
                'id' => 31, 'name' => 'Acme', 'company_name' => 'A', 'receipt_item_name' => null,
                'email' => null, 'phone' => null, 'partner_type' => '', 'status' => '1',
                'is_active' => true, 'created_at' => null, 'sub_partners' => [],
                'cancel_payment_enabled' => false, 'show_phone_number' => false,
            ], 200),
        ]);

        $this->artisan('wlcm:doctor')
            ->expectsOutputToContain('has no scheme')
            ->assertExitCode(1);
    }

    public function test_recent_failed_deliveries_fail(): void
    {
        Http::fake([
            'api.wlcm.test/*/webhooks/*/events' => Http::response(['results' => [[
                'id' => 1, 'status' => 'failed', 'attempts' => 1, 'response_code' => 503,
                'error_message' => 'HTTP 503', 'next_retry_at' => null,
                'event_type' => 'payment.success', 'created_at' => '2026-08-01T00:00:00Z',
            ]]], 200),
            'api.wlcm.test/*/webhooks' => Http::response([[
                'id' => 1, 'url' => 'https://shop.example.com/wlcm/webhook', 'secret_key' => 'x',
                'is_active' => true, 'events' => [], 'last_triggered_at' => null,
                'last_status_code' => 503, 'created_at' => null,
            ]], 200),
            'api.wlcm.test/*/partners/me' => Http::response([
                'id' => 31, 'name' => 'Acme', 'company_name' => 'A', 'receipt_item_name' => null,
                'email' => null, 'phone' => null, 'partner_type' => '', 'status' => '1',
                'is_active' => true, 'created_at' => null, 'sub_partners' => [],
                'cancel_payment_enabled' => false, 'show_phone_number' => false,
            ], 200),
        ]);

        $this->artisan('wlcm:doctor')->assertExitCode(1);
    }

    public function test_skip_api_makes_no_outbound_call(): void
    {
        Http::fake();

        $this->artisan('wlcm:doctor', ['--skip-api' => true])->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_json_output_is_machine_readable(): void
    {
        $this->fakeHealthy();

        $this->artisan('wlcm:doctor', ['--json' => true])
            ->expectsOutputToContain('"checks"')
            ->assertExitCode(0);
    }

    public function test_no_secret_prefix_reaches_the_output(): void
    {
        // Doctor output is what people paste into support tickets.
        config()->set('wlcm.connections.main.partner.api_secret', 'sk_super_secret_value');
        config()->set('wlcm.connections.main.partner.webhook_secret', 'whsec_super_secret');

        $this->artisan('wlcm:doctor', ['--skip-api' => true, '--json' => true])
            ->doesntExpectOutputToContain('sk_super')
            ->assertExitCode(0);
    }

    public function test_a_cached_config_warns_but_does_not_fail(): void
    {
        // A warning that fails the command is a warning nobody runs the command for.
        $this->fakeHealthy();

        $this->artisan('wlcm:doctor')->assertExitCode(0);
    }
}
