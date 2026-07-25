<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Uzbek\Wlcm\Tests\TestCase;

final class WebhookRoutePathTest extends TestCase
{
    protected function useArrayPath(Application $app): void
    {
        $app['config']->set('wlcm.webhook.path', ['array']);
    }

    protected function useSlashOnlyPath(Application $app): void
    {
        $app['config']->set('wlcm.webhook.path', '///');
    }

    protected function useWhitespacePath(Application $app): void
    {
        $app['config']->set('wlcm.webhook.path', '   ');
    }

    protected function useCustomPath(Application $app): void
    {
        $app['config']->set('wlcm.webhook.path', 'hooks/x');
    }

    /** @return array<string, string> */
    private function webhookData(): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '10.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    #[DefineEnvironment('useArrayPath')]
    public function test_array_typed_path_config_falls_back_to_default(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
    }

    #[DefineEnvironment('useSlashOnlyPath')]
    public function test_slash_only_path_config_falls_back_to_default(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
    }

    #[DefineEnvironment('useSlashOnlyPath')]
    public function test_slash_only_path_config_does_not_shadow_the_app_root(): void
    {
        $this->postJson('/', $this->webhookData())->assertNotFound();
    }

    #[DefineEnvironment('useWhitespacePath')]
    public function test_whitespace_only_path_config_falls_back_without_shadowing_the_app_root(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
        $this->postJson('/', $this->webhookData())->assertNotFound();
    }

    #[DefineEnvironment('useCustomPath')]
    public function test_custom_path_config_replaces_the_default_path(): void
    {
        $this->postJson('/hooks/x', $this->webhookData())->assertOk();
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertNotFound();
    }
}
