<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Tests\TestCase;

final class StorageWiringTest extends TestCase
{
    use RefreshDatabase;

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

    private function webhookData(string $state = '2'): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => $state, 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:{$state}:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    public function test_outgoing_request_logged_and_masked(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['order_id' => 1, 'state' => 1], 201)]);

        Wlcm::partner()->checkout()->create(
            amount: 120000, externalId: 'ORD-9', provider: 'card',
            cardNumber: '9860991234569999', expireDate: '3003',
        );

        $log = WlcmLog::sole();
        $this->assertSame('out', $log->direction);
        $this->assertSame('986099******9999', $log->request_body['card_number']);
        $this->assertSame('***', $log->request_body['expire_date']);
        $this->assertSame(201, $log->status_code);
    }

    public function test_checkout_recorded_as_transaction(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-9', 'state' => 0,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);

        Wlcm::partner()->checkout()->create(amount: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $tx = WlcmTransaction::sole();
        $this->assertSame('ORD-9', $tx->external_id);
        $this->assertSame(120000, $tx->amount_tiyin);
        $this->assertSame(0, $tx->state);
    }

    public function test_webhook_updates_state_and_dedupes(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);

        $tx = WlcmTransaction::sole();
        $this->assertSame(2, $tx->state);
        $this->assertSame('7788', $tx->payment_id);

        $this->assertSame(1, WlcmLog::where('result', 'duplicate')->count());
    }

    public function test_webhook_claims_checkout_row_no_orphan(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-1', 'state' => 0,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);

        Wlcm::partner()->checkout()->create(amount: 120000, returnUrl: 'https://x.test', externalId: 'ORD-1');

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();

        $tx = WlcmTransaction::sole();
        $this->assertSame('7788', $tx->payment_id);
        $this->assertSame(2, $tx->state);
        $this->assertSame(120000, $tx->amount_tiyin);
    }

    public function test_checkout_survives_broken_log_table(): void
    {
        Schema::drop('wlcm_logs');
        Exceptions::fake();
        Http::fake(['api.wlcm.test/*' => Http::response(['order_id' => 1, 'state' => 0, 'checkout_url' => 'https://c.test/x'], 201)]);

        $r = Wlcm::partner()->checkout()->create(amount: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $this->assertSame('https://c.test/x', $r->checkoutUrl);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_checkout_survives_broken_transactions_table(): void
    {
        Schema::drop('wlcm_transactions');
        Exceptions::fake();
        Http::fake(['api.wlcm.test/*' => Http::response(['order_id' => 1, 'state' => 0, 'checkout_url' => 'https://c.test/x'], 201)]);

        $r = Wlcm::partner()->checkout()->create(amount: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $this->assertSame('https://c.test/x', $r->checkoutUrl);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_webhook_create_never_logs_secret_key(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['id' => 5, 'url' => 'https://a.test/h', 'secret_key' => 'whsec_x', 'is_active' => true, 'created_at' => '2026-07-25'], 201)]);

        Wlcm::partner()->webhooks()->create('https://a.test/h', 'whsec_x');

        $log = WlcmLog::sole();
        $this->assertArrayNotHasKey('secret_key', $log->request_body);
        $this->assertArrayNotHasKey('secret_key', $log->response_body);
    }
}
