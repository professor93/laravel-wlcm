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
use Uzbek\Wlcm\Events\SubscriptionCharged;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\ServerException;
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

    private function subscriptionWebhookData(string $event = 'subscription.charged'): array
    {
        $data = [
            'event' => $event, 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        $data['signature'] = hash_hmac('sha256', "order-1001:{$event}:1745740800000", 'whsec_test');

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

    public function test_card_attach_logged_with_masked_pan(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'card_id' => 7, 'otp_sent_phone' => '**********67', 'reused' => false,
        ], 200)]);

        Wlcm::partner()->subscriptions()->attachCard(5, '9860991234569999', '3003', '998901234567');

        $log = WlcmLog::sole();
        $this->assertSame('out', $log->direction);
        $this->assertSame('986099******9999', $log->request_body['card_number']);
        $this->assertSame('***', $log->request_body['expire_date']);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('ok', $log->result);
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
        $this->assertNotNull($tx->processed_at);
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

    public function test_replayed_success_after_cancel_is_stale(): void
    {
        Event::fake();

        $success = $this->webhookData('2');
        $this->postJson('/wlcm/webhook', $success)->assertOk();
        $this->postJson('/wlcm/webhook', $this->webhookData('-2'))->assertOk();
        $this->postJson('/wlcm/webhook', $success)->assertOk();

        $this->assertSame(-2, WlcmTransaction::sole()->state);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        $this->assertSame(1, WlcmLog::where('result', 'stale')->count());
    }

    public function test_replay_via_init_state_cannot_reopen_terminal_row(): void
    {
        Event::fake();
        $success = $this->webhookData('2');

        $this->postJson('/wlcm/webhook', $success)->assertOk();
        $this->postJson('/wlcm/webhook', $this->webhookData('-2'))->assertOk();
        $this->postJson('/wlcm/webhook', $this->webhookData('0'))->assertOk();
        $this->postJson('/wlcm/webhook', $success)->assertOk();

        $this->assertSame(-2, WlcmTransaction::sole()->state);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        $this->assertSame(2, WlcmLog::query()->where('result', 'stale')->count());
    }

    public function test_crash_recovery_refires_events(): void
    {
        // Row claimed but processed_at never set (handler crashed) -> same delivery re-fires.
        $data = $this->webhookData('2');
        Event::fake();
        $this->postJson('/wlcm/webhook', $data)->assertOk();
        WlcmTransaction::query()->update(['processed_at' => null]);

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        Event::assertDispatchedTimes(PaymentSucceeded::class, 2);
    }

    public function test_webhook_updates_preexisting_row_without_duplicating(): void
    {
        WlcmTransaction::create([
            'connection' => 'main', 'api' => 'partner', 'external_id' => 'ORD-1',
            'order_id' => '1254', 'payment_id' => '7788', 'state' => 0, 'processed_at' => null,
        ]);

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();

        $tx = WlcmTransaction::sole();
        $this->assertSame(2, $tx->state);
        $this->assertNotNull($tx->processed_at);
    }

    public function test_subscription_delivery_deduped(): void
    {
        Event::fake();
        $data = $this->subscriptionWebhookData();

        $this->postJson('/wlcm/webhook', $data)->assertOk();
        $this->postJson('/wlcm/webhook', $data)->assertOk();

        Event::assertDispatchedTimes(SubscriptionCharged::class, 1);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
    }

    public function test_distinct_subscription_deliveries_both_processed(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData())->assertOk();
        $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData('subscription.cancelled'))->assertOk();

        $this->assertSame(2, WlcmTransaction::where('api', 'subscription')->count());
        Event::assertDispatchedTimes(WebhookReceived::class, 2);
    }

    public function test_webhook_500_when_transactions_table_broken(): void
    {
        Schema::drop('wlcm_transactions');

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertStatus(500);
    }

    public function test_webhook_survives_broken_log_table(): void
    {
        Schema::drop('wlcm_logs');
        Exceptions::fake();

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
        Exceptions::assertReported(QueryException::class);
    }

    public function test_non_json_success_body_logs_error_result(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            '<html>502 Bad Gateway (but says 200)</html>', 200, ['Content-Type' => 'text/html'],
        )]);

        try {
            Wlcm::partner()->checkout()->create(amount: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');
            $this->fail('expected ServerException');
        } catch (ServerException) {
        }

        $log = WlcmLog::sole();
        $this->assertSame('error', $log->result);
        $this->assertSame(200, $log->status_code);
    }

    public function test_confirm_card_otp_masked_in_both_bodies(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['status' => 'confirmed', 'otp' => '888888'], 200)]);

        Wlcm::partner()->subscriptions()->confirmCard(5, '888888', 3);

        $log = WlcmLog::sole();
        $this->assertSame('***', $log->request_body['otp']);
        $this->assertSame('***', $log->response_body['otp']);
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
