<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookOutcome;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\WebhookPayload;
use Uzbek\Wlcm\WlcmServiceProvider;

final class ConfigTogglesTest extends TestCase
{
    use RefreshDatabase;

    private const array ON = [true, '1', 1];

    private const array OFF = [false, '0', 0, null, ''];

    private const array UNRECOGNISED = ['off', 'no', 'none', 'disabled', 'false', 'true', 'yes', 'maybe', 2, 1.0, []];

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function offFalse(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', false);
    }

    protected function offZeroString(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', '0');
    }

    protected function offZeroInt(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', 0);
    }

    protected function offNull(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', null);
    }

    protected function offEmpty(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', '');
    }

    protected function onTrue(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', true);
    }

    protected function onOneString(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', '1');
    }

    protected function onOneInt(Application $app): void
    {
        $app['config']->set('wlcm.webhook.enabled', 1);
    }

    /** @return array<string, string> */
    private function webhookData(): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    private function payload(string $paymentId): WebhookPayload
    {
        return WebhookPayload::fromArray([
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => $paymentId,
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00', 'signature' => 'sig',
        ], 'main');
    }

    #[DefineEnvironment('offFalse')]
    public function test_false_leaves_the_public_route_unregistered(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertNotFound();
    }

    #[DefineEnvironment('offZeroString')]
    public function test_zero_string_leaves_the_public_route_unregistered(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertNotFound();
    }

    #[DefineEnvironment('offZeroInt')]
    public function test_zero_int_leaves_the_public_route_unregistered(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertNotFound();
    }

    #[DefineEnvironment('offNull')]
    public function test_null_leaves_the_public_route_unregistered(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertNotFound();
    }

    #[DefineEnvironment('offEmpty')]
    public function test_empty_string_leaves_the_public_route_unregistered(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertNotFound();
    }

    #[DefineEnvironment('onTrue')]
    public function test_true_registers_the_public_route(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
    }

    #[DefineEnvironment('onOneString')]
    public function test_one_string_registers_the_public_route(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
    }

    #[DefineEnvironment('onOneInt')]
    public function test_one_int_registers_the_public_route(): void
    {
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
    }

    public function test_unrecognised_webhook_enabled_values_stop_the_boot(): void
    {
        foreach (self::UNRECOGNISED as $value) {
            config()->set('wlcm.webhook.enabled', $value);

            try {
                (new WlcmServiceProvider(app()))->boot();
                $this->fail('boot accepted '.var_export($value, true));
            } catch (MissingConfigurationException $e) {
                $this->assertSame('Missing configuration: wlcm.webhook.enabled', $e->getMessage());
            }
        }
    }

    public function test_unrecognised_transactions_values_are_a_config_error(): void
    {
        foreach (self::UNRECOGNISED as $value) {
            config()->set('wlcm.storage.transactions', $value);

            try {
                app(TransactionStore::class)->applyWebhook($this->payload('7788'), 'tok');
                $this->fail('recorder accepted '.var_export($value, true));
            } catch (MissingConfigurationException $e) {
                $this->assertSame('Missing configuration: wlcm.storage.transactions', $e->getMessage());
            }
        }
    }

    public function test_documented_on_values_record_transactions(): void
    {
        foreach (self::ON as $index => $value) {
            config()->set('wlcm.storage.transactions', $value);
            app(TransactionStore::class)->applyWebhook($this->payload("77{$index}"), 'tok');
        }

        $this->assertSame(count(self::ON), WlcmTransaction::query()->count());
    }

    public function test_documented_off_values_record_nothing(): void
    {
        foreach (self::OFF as $index => $value) {
            config()->set('wlcm.storage.transactions', $value);

            $this->assertSame(
                WebhookOutcome::Fresh,
                app(TransactionStore::class)->applyWebhook($this->payload("77{$index}"), 'tok'),
            );
        }

        $this->assertSame(0, WlcmTransaction::query()->count());
    }
}
