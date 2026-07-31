<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Tests\TestCase;

final class WebhookCreateCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = sys_get_temp_dir().'/wlcm-hook-'.bin2hex(random_bytes(6));
        file_put_contents($this->envPath, "APP_NAME=Acme\n");
        $this->app->useEnvironmentPath(dirname($this->envPath));
        $this->app->loadEnvironmentFrom(basename($this->envPath));

        config()->set('app.url', 'https://shop.example.com');
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);
        parent::tearDown();
    }

    private function fakeCreate(?string $serverSecret = null): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'id' => 7, 'url' => 'https://shop.example.com/wlcm/webhook',
            'secret_key' => $serverSecret, 'is_active' => true, 'events' => [],
            'last_triggered_at' => null, 'last_status_code' => null, 'created_at' => null,
        ], 200)]);
    }

    public function test_it_derives_the_url_from_app_url_and_the_configured_path(): void
    {
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--no-interaction' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '"url":"https://shop.example.com/wlcm/webhook"'));
    }

    public function test_the_configured_domain_outranks_app_url(): void
    {
        config()->set('wlcm.webhook.domain', 'hooks.example.com');
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--no-interaction' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), 'https://hooks.example.com/wlcm/webhook'));
    }

    public function test_a_custom_path_is_honoured(): void
    {
        config()->set('wlcm.webhook.path', 'payments/incoming');
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--no-interaction' => true])->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), 'https://shop.example.com/payments/incoming'));
    }

    public function test_a_non_default_connection_gets_its_segment(): void
    {
        config()->set('wlcm.connections.shop.partner', [
            'base_url' => 'https://api.wlcm.test/api/v1',
            'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => 'w',
        ]);
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--connection' => 'shop', '--no-interaction' => true])
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '/wlcm/webhook/shop'));
    }

    public function test_it_generates_a_strong_secret_when_none_is_given(): void
    {
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--no-interaction' => true])->assertExitCode(0);

        Http::assertSent(function (Request $r): bool {
            $sent = json_decode($r->body(), true);
            $secret = is_array($sent) ? ($sent['secret_key'] ?? '') : '';

            // 32 random bytes, hex encoded.
            return is_string($secret) && strlen($secret) === 64 && ctype_xdigit($secret);
        });
    }

    public function test_it_writes_the_secret_to_the_env(): void
    {
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--secret' => 'whsec_chosen', '--no-interaction' => true])
            ->assertExitCode(0);

        $this->assertStringContainsString('WLCM_WEBHOOK_SECRET=whsec_chosen', (string) file_get_contents($this->envPath));
    }

    public function test_it_stores_the_secret_the_server_reports_not_the_one_we_sent(): void
    {
        // Deliveries are signed with whatever WLCM kept, so that is what must be stored.
        $this->fakeCreate(serverSecret: 'whsec_server_chose_this');

        $this->artisan('wlcm:webhook:create', ['--secret' => 'whsec_we_asked_for', '--no-interaction' => true])
            ->assertExitCode(0);

        $env = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('WLCM_WEBHOOK_SECRET=whsec_server_chose_this', $env);
        $this->assertStringNotContainsString('whsec_we_asked_for', $env);
    }

    public function test_it_never_overwrites_an_existing_secret(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Acme\nWLCM_WEBHOOK_SECRET=live-secret\n");
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--secret' => 'whsec_new', '--no-interaction' => true])
            ->expectsOutputToContain('was NOT changed')
            ->assertExitCode(0);

        $env = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('WLCM_WEBHOOK_SECRET=live-secret', $env);
        $this->assertStringNotContainsString("\nWLCM_WEBHOOK_SECRET=whsec_new", $env);
    }

    public function test_a_non_default_connection_prints_config_rather_than_writing_env(): void
    {
        config()->set('wlcm.connections.shop.partner', [
            'base_url' => 'https://api.wlcm.test/api/v1',
            'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => 'w',
        ]);
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--connection' => 'shop', '--secret' => 'whsec_x', '--no-interaction' => true])
            ->expectsOutputToContain('does not read WLCM_WEBHOOK_SECRET')
            ->assertExitCode(0);

        $this->assertStringNotContainsString('WLCM_WEBHOOK_SECRET', (string) file_get_contents($this->envPath));
    }

    public function test_it_warns_about_a_host_wlcm_cannot_reach(): void
    {
        config()->set('app.url', 'http://localhost');
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--no-interaction' => true])
            ->expectsOutputToContain('not reachable from the internet')
            ->assertExitCode(0);
    }

    public function test_an_explicit_url_skips_derivation(): void
    {
        $this->fakeCreate();

        $this->artisan('wlcm:webhook:create', ['--url' => 'https://other.example.com/custom', '--no-interaction' => true])
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), 'https://other.example.com/custom'));
    }

    public function test_no_host_anywhere_is_a_usage_error(): void
    {
        config()->set('app.url', null);
        Http::fake();

        $this->artisan('wlcm:webhook:create', ['--no-interaction' => true])->assertExitCode(2);

        Http::assertNothingSent();
    }
}
