<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Tests\TestCase;

// The two audit tables must not disagree about a pan hiding in merchant prose.
final class TransactionPanScrubTest extends TestCase
{
    use RefreshDatabase;

    private const string PAN = '4111111111111111';

    private const string SCRUBBED = '411111***1111';

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('wlcm.storage.logs', true);
        $app['config']->set('wlcm.storage.transactions', true);
    }

    /** @return array<string, string> */
    private function webhookData(string $externalId): array
    {
        $data = [
            'external_id' => $externalId, 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    public function test_a_pan_in_a_checkout_reference_is_scrubbed_in_both_audit_tables(): void
    {
        // Pan rides the merchant reference, the only id a merchant writes. The server
        // mints transaction_id, so it carries a server-shaped value here.
        $prose = 'order for card '.self::PAN;
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => $prose, 'state' => 1,
            'checkout_url' => 'https://c.test/x', 'transaction_id' => 'mock_tx_1784911325121',
        ], 201)]);

        Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: $prose,
        );

        $log = WlcmLog::sole();
        $tx = WlcmTransaction::sole();

        $this->assertSame('order for card '.self::SCRUBBED, $tx->external_id);
        $this->assertSame($log->external_id, $tx->external_id);
        $this->assertSame($log->transaction_id, $tx->transaction_id);
        $this->assertStringNotContainsString(self::PAN, (string) json_encode($tx->toArray()));
        $this->assertStringNotContainsString(self::PAN, (string) json_encode($log->toArray()));
    }

    public function test_ordinary_ids_stay_byte_intact_in_both_tables(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-9', 'state' => 1,
            'checkout_url' => 'https://c.test/x', 'transaction_id' => 'tx_9c1',
        ], 201)]);

        Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9',
        );

        $tx = WlcmTransaction::sole();
        $this->assertSame('ORD-9', $tx->external_id);
        $this->assertSame('1254', $tx->order_id);
        $this->assertSame('tx_9c1', $tx->transaction_id);
        $this->assertSame('ORD-9', WlcmLog::sole()->external_id);
    }

    public function test_a_pan_in_a_webhook_reference_is_scrubbed_in_both_audit_tables(): void
    {
        Event::fake();
        $prose = 'refund for card '.self::PAN;

        $this->postJson('/wlcm/webhook', $this->webhookData($prose))->assertOk();

        $log = WlcmLog::sole();
        $tx = WlcmTransaction::sole();

        $this->assertSame('refund for card '.self::SCRUBBED, $tx->external_id);
        $this->assertSame($log->external_id, $tx->external_id);
        $this->assertStringNotContainsString(self::PAN, (string) json_encode($tx->toArray()));
        $this->assertStringNotContainsString(self::PAN, (string) json_encode($log->toArray()));

        // Scrubbing a correlation key must not cost the row its own claim and dedup.
        $this->assertNotNull($tx->refresh()->processed_at);
        $this->postJson('/wlcm/webhook', $this->webhookData($prose))->assertOk();
        $this->assertSame(1, WlcmTransaction::query()->count());
    }

    public function test_a_pan_in_a_subscription_reference_is_scrubbed_in_the_transaction_row(): void
    {
        Event::fake();
        $prose = 'sub for card '.self::PAN;
        $data = [
            'event' => 'subscription.charged', 'external_order_id' => $prose,
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        $data['signature'] = hash_hmac('sha256', "{$prose}:subscription.charged:1745740800000", 'whsec_test');

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        $tx = WlcmTransaction::sole();
        $this->assertSame('sub for card '.self::SCRUBBED, $tx->external_id);
        $this->assertSame(WlcmLog::sole()->external_id, $tx->external_id);
        $this->assertStringNotContainsString(self::PAN, (string) json_encode($tx->toArray()));

        $this->postJson('/wlcm/webhook', $data)->assertOk();
        $this->assertSame(1, WlcmTransaction::query()->count());
    }
}
