<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

final class WebhookIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $timestamp = (string) now()->getTimestamp();
        $base = "1:2:2:{$timestamp}";
        $signature = hash_hmac('sha256', $base, 'whsec_test');

        return [
            'order_id' => '1', 'payment_id' => '2', 'amount' => 1000,
            'state' => '2', 'timestamp' => $timestamp, 'signature' => $signature,
        ];
    }

    /** @return TestResponse<Response> */
    private function deliver(string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/'.WebhookRoute::path(config('wlcm.webhook.path')), $this->payload());
    }

    public function test_an_absent_allowlist_lets_everything_through(): void
    {
        Event::fake();

        $this->deliver('203.0.113.9')->assertOk();

        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_an_allowed_address_still_reaches_the_controller(): void
    {
        config()->set('wlcm.webhook.allowed_ips', '203.0.113.0/24');
        Event::fake();

        $this->deliver('203.0.113.9')->assertOk();

        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_a_disallowed_address_is_refused_and_nothing_fires(): void
    {
        config()->set('wlcm.webhook.allowed_ips', '203.0.113.0/24');
        Event::fake();

        $this->deliver('198.51.100.7')->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_the_allowlist_runs_before_signature_verification(): void
    {
        // A disallowed address must be refused even when the signature is nonsense —
        // proving the cheap check runs first and no secret work happened.
        config()->set('wlcm.webhook.allowed_ips', '203.0.113.0/24');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/'.WebhookRoute::path(config('wlcm.webhook.path')), [
                'order_id' => '1', 'payment_id' => '2', 'amount' => 1000,
                'state' => '2', 'timestamp' => (string) now()->getTimestamp(),
                'signature' => 'not-even-close',
            ])
            ->assertForbidden();
    }

    public function test_a_rejection_leaves_an_audit_line_naming_the_address(): void
    {
        config()->set('wlcm.storage.logs', 'database');
        config()->set('wlcm.webhook.allowed_ips', '203.0.113.0/24');

        $this->deliver('198.51.100.7')->assertForbidden();

        $row = WlcmLog::query()->latest('id')->first();
        $this->assertNotNull($row, 'the rejection was not recorded at all');
        $this->assertSame('rejected', $row->getAttribute('result'));
        // The address is the point; nothing else from an unverified request is kept.
        $this->assertStringContainsString('198.51.100.7', (string) json_encode($row->request_body));
    }

    public function test_a_malformed_entry_is_loud_rather_than_silently_refusing(): void
    {
        config()->set('wlcm.webhook.allowed_ips', '203.0.113.0/24, nonsense');

        // A 500 is deliberate: an allowlist that can never match is a broken control,
        // and refusing every delivery in silence is the failure mode being prevented.
        $this->withoutExceptionHandling();
        $this->expectException(MissingConfigurationException::class);

        $this->deliver('203.0.113.9');
    }
}
