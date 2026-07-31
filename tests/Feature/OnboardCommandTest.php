<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Tests\TestCase;

final class OnboardCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = sys_get_temp_dir().'/wlcm-onboard-'.bin2hex(random_bytes(6));
        file_put_contents($this->envPath, "APP_NAME=Acme\n");
        $this->app->useEnvironmentPath(dirname($this->envPath));
        $this->app->loadEnvironmentFrom(basename($this->envPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);
        parent::tearDown();
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'api.wlcm.test/*' => Http::sequence()
                ->push(['valid' => true], 200)
                ->push([
                    'id' => 27, 'name' => 'Acme', 'api_key' => 'wlcm_abc',
                    'api_secret' => 'sk_live_xyz', 'warning' => 'Save these now.',
                ], 200),
        ]);
    }

    public function test_it_registers_and_writes_the_env(): void
    {
        $this->fakeSuccess();

        $this->artisan('wlcm:onboard', ['--token' => 'T', '--name' => 'Acme', '--no-interaction' => true])
            ->assertExitCode(0);

        $env = (string) file_get_contents($this->envPath);
        $this->assertStringContainsString('WLCM_API_KEY=wlcm_abc', $env);
        $this->assertStringContainsString('WLCM_API_SECRET=sk_live_xyz', $env);
        $this->assertStringContainsString('WLCM_BASE_URL=https://api.wlcm.test/api/v1', $env);
    }

    public function test_it_shows_the_secret_and_the_servers_own_warning(): void
    {
        $this->fakeSuccess();

        $this->artisan('wlcm:onboard', ['--token' => 'T', '--no-interaction' => true])
            ->expectsOutputToContain('sk_live_xyz')
            ->expectsOutputToContain('Save these now.')
            ->assertExitCode(0);
    }

    public function test_it_never_overwrites_an_existing_key(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Acme\nWLCM_API_KEY=already-here\n");
        $this->fakeSuccess();

        $this->artisan('wlcm:onboard', ['--token' => 'T', '--no-interaction' => true])
            ->assertExitCode(0);

        $env = (string) file_get_contents($this->envPath);
        // The binding rule: never overwrite, and non-interactive must not even append a
        // commented duplicate.
        $this->assertStringContainsString('WLCM_API_KEY=already-here', $env);
        $this->assertStringNotContainsString('WLCM_API_KEY=wlcm_abc', $env);
        $this->assertStringNotContainsString('# WLCM_API_KEY=wlcm_abc', $env);
        // The secret was still missing, so it is still appended.
        $this->assertStringContainsString('WLCM_API_SECRET=sk_live_xyz', $env);
    }

    public function test_a_rejected_token_fails_without_touching_the_env(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'invalid_or_expired'], 400)]);

        $this->artisan('wlcm:onboard', ['--token' => 'bad', '--no-interaction' => true])
            ->assertExitCode(1);

        $this->assertStringNotContainsString('WLCM_API_KEY', (string) file_get_contents($this->envPath));
    }

    public function test_a_non_default_connection_prints_config_instead_of_writing_env(): void
    {
        config()->set('wlcm.connections.shop.partner.base_url', 'https://api.wlcm.test/api/v1');
        $this->fakeSuccess();

        $this->artisan('wlcm:onboard', ['--token' => 'T', '--connection' => 'shop', '--no-interaction' => true])
            ->expectsOutputToContain('not the default')
            ->assertExitCode(0);

        // Writing WLCM_API_KEY here would have configured the DEFAULT connection while
        // claiming to configure "shop".
        $this->assertStringNotContainsString('WLCM_API_KEY', (string) file_get_contents($this->envPath));
    }

    public function test_it_refuses_without_a_token_in_non_interactive_mode(): void
    {
        Http::fake();

        $this->artisan('wlcm:onboard', ['--no-interaction' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }
}
