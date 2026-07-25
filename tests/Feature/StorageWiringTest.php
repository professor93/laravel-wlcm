<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Throwable;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Events\SubscriptionCharged;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\ConflictException;
use Uzbek\Wlcm\Exceptions\ServerException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookOutcome;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

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

    private function prune(): void
    {
        $command = $this->artisan('model:prune', ['--model' => [WlcmTransaction::class]]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0);
    }

    /** @return array<string, string> */
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

    /** @return array<string, int|string> */
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
            amountTiyin: 120000, externalId: 'ORD-9', provider: 'card',
            cardNumber: '9860991234569999', expireDate: '3003',
        );

        $log = WlcmLog::sole();
        $this->assertSame('out', $log->direction);
        $this->assertIsArray($log->request_body);
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
        $this->assertIsArray($log->request_body);
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

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

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

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-1');

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

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $this->assertSame('https://c.test/x', $r->checkoutUrl);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_checkout_survives_broken_transactions_table(): void
    {
        Schema::drop('wlcm_transactions');
        Exceptions::fake();
        Http::fake(['api.wlcm.test/*' => Http::response(['order_id' => 1, 'state' => 0, 'checkout_url' => 'https://c.test/x'], 201)]);

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $this->assertSame('https://c.test/x', $r->checkoutUrl);
        Exceptions::assertReported(QueryException::class);
    }

    public function test_a_throwing_report_surfaces_to_the_caller(): void
    {
        Schema::drop('wlcm_logs');
        $this->app->instance(ExceptionHandler::class, new class(app()) extends Handler
        {
            public function report(Throwable $e): void
            {
                throw new RuntimeException('log channel is down');
            }
        });
        Http::fake(['api.wlcm.test/*' => Http::response(['order_id' => 1, 'state' => 0, 'checkout_url' => 'https://c.test/x'], 201)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('log channel is down');

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');
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

    public function test_real_subscription_delivery_leaves_a_claim_row_shaped_for_the_reaper_exemption(): void
    {
        $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData())->assertOk();

        $claim = WlcmTransaction::sole();
        $this->assertSame('subscription', $claim->getAttribute('api'));
        $this->assertNull($claim->payment_id);
        $deliveryKey = $claim->getAttribute('delivery_key');
        $this->assertIsString($deliveryKey);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $deliveryKey);
        $this->assertNotNull($claim->processed_at);
    }

    public function test_distinct_subscription_deliveries_both_processed(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData())->assertOk();
        $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData('subscription.cancelled'))->assertOk();

        $this->assertSame(2, WlcmTransaction::where('api', 'subscription')->count());
        Event::assertDispatchedTimes(WebhookReceived::class, 2);
    }

    public function test_subscription_deliveries_log_ok_or_duplicate_on_replay(): void
    {
        $data = $this->subscriptionWebhookData();

        $this->postJson('/wlcm/webhook', $data)->assertOk();
        $this->postJson('/wlcm/webhook', $data)->assertOk();

        $this->assertSame(['ok', 'duplicate'], WlcmLog::query()->orderBy('id')->pluck('result')->all());
    }

    public function test_a_late_webhook_for_a_reaped_checkout_still_dispatches_as_a_new_row(): void
    {
        config()->set('wlcm.storage.prune_days', null);
        config()->set('wlcm.storage.stale_days', 30);
        Event::fake();

        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-1', 'state' => 0,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);
        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-1');
        WlcmTransaction::query()->update(['created_at' => now()->subDays(31)]);

        $this->prune();
        $this->assertSame(0, WlcmTransaction::query()->count());

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();

        $tx = WlcmTransaction::sole();
        $this->assertSame('7788', $tx->payment_id);
        $this->assertSame(2, $tx->state);
        Event::assertDispatched(PaymentSucceeded::class);
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
            Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');
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
        $this->assertIsArray($log->request_body);
        $this->assertIsArray($log->response_body);
        $this->assertSame('***', $log->request_body['otp']);
        $this->assertSame('***', $log->response_body['otp']);
    }

    public function test_webhook_create_never_logs_secret_key(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['id' => 5, 'url' => 'https://a.test/h', 'secret_key' => 'whsec_x', 'is_active' => true, 'created_at' => '2026-07-25'], 201)]);

        Wlcm::partner()->webhooks()->create('https://a.test/h', 'whsec_x');

        $log = WlcmLog::sole();
        $this->assertIsArray($log->request_body);
        $this->assertIsArray($log->response_body);
        $this->assertArrayNotHasKey('secret_key', $log->request_body);
        $this->assertArrayNotHasKey('secret_key', $log->response_body);
    }

    public function test_bare_pan_echo_never_lands_in_response_log(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [
                [
                    'type' => 'string_pattern_mismatch',
                    'loc' => ['body', 'card_number'],
                    'msg' => 'String should match pattern \'^[0-9]{16}$\'',
                    'input' => '8600123412341234',
                ],
            ],
        ], 400)]);

        try {
            Wlcm::partner()->checkout()->create(
                amountTiyin: 120000, externalId: 'ORD-9', provider: 'card',
                cardNumber: '8600123412341234', expireDate: '3003',
            );
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
        }

        $log = WlcmLog::sole();
        $this->assertIsArray($log->response_body);
        $detail = $log->response_body['detail'];
        $this->assertIsArray($detail);
        $this->assertIsArray($detail[0]);
        $this->assertSame('860012******1234', $detail[0]['input']);
    }

    public function test_array_typed_wire_id_does_not_break_a_successful_charge(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['status' => 'success', 'payment_id' => ['9']], 200)]);

        $result = Wlcm::partner()->subscriptions()->charge(5, 10000, 7);

        $this->assertTrue($result->succeeded());
        $this->assertNull($result->paymentId);

        $log = WlcmLog::sole();
        $this->assertNull($log->payment_id);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('ok', $log->result);
    }

    public function test_array_typed_webhook_id_still_lands_in_the_audit_trail(): void
    {
        $data = $this->webhookData();
        $data['external_id'] = ['ORD-1', 'x'];

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        $this->assertSame(['ok'], WlcmLog::query()->orderBy('id')->pluck('result')->all());

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        $this->assertSame(['ok', 'duplicate'], WlcmLog::query()->orderBy('id')->pluck('result')->all());
    }

    public function test_log_store_is_swappable(): void
    {
        // Swap BEFORE the first Wlcm:: call — the manager caches the connection.
        $spy = new class implements LogStore
        {
            /** @var list<string> */
            public array $calls = [];

            public function out(string $connection, string $api, string $method, string $url, ?array $requestBody, mixed $responseBody, ?int $status, int $durationMs, array $ids = [], string $result = 'ok'): void
            {
                $this->calls[] = $result;
            }

            public function webhook(string $connection, array $payload, string $result): void
            {
                $this->calls[] = "webhook:{$result}";
            }
        };
        $this->app->instance(LogStore::class, $spy);

        Http::fake(['api.wlcm.test/*' => Http::response(['order_id' => 1, 'state' => 0, 'checkout_url' => 'https://c.test/x'], 201)]);
        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $this->assertSame(['ok'], $spy->calls);
        $this->assertSame(0, WlcmLog::query()->count());
    }

    public function test_transaction_store_is_swappable(): void
    {
        $spy = new class implements TransactionStore
        {
            /** @var list<string> */
            public array $calls = [];

            public function applyWebhook(WebhookPayload $payload): WebhookOutcome
            {
                $this->calls[] = 'applyWebhook';

                return WebhookOutcome::Fresh;
            }

            public function markProcessed(WebhookPayload $payload): void
            {
                $this->calls[] = 'markProcessed';
            }

            public function claimSubscription(SubscriptionWebhookPayload $payload): WebhookOutcome
            {
                $this->calls[] = 'claimSubscription';

                return WebhookOutcome::Fresh;
            }

            public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload): void
            {
                $this->calls[] = 'markSubscriptionProcessed';
            }

            public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void
            {
                $this->calls[] = 'recordCheckout';
            }
        };
        $this->app->instance(TransactionStore::class, $spy);

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();

        $this->assertSame(['applyWebhook', 'markProcessed'], $spy->calls);
        $this->assertSame(0, WlcmTransaction::query()->count());
    }

    public function test_swapped_transaction_store_takes_the_subscription_and_checkout_paths_too(): void
    {
        $spy = new class implements TransactionStore
        {
            /** @var list<string> */
            public array $calls = [];

            public function applyWebhook(WebhookPayload $payload): WebhookOutcome
            {
                $this->calls[] = 'applyWebhook';

                return WebhookOutcome::Fresh;
            }

            public function markProcessed(WebhookPayload $payload): void
            {
                $this->calls[] = 'markProcessed';
            }

            public function claimSubscription(SubscriptionWebhookPayload $payload): WebhookOutcome
            {
                $this->calls[] = 'claimSubscription';

                return WebhookOutcome::Fresh;
            }

            public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload): void
            {
                $this->calls[] = 'markSubscriptionProcessed';
            }

            public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void
            {
                $this->calls[] = 'recordCheckout';
            }
        };
        $this->app->instance(TransactionStore::class, $spy);

        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-9', 'state' => 0,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);
        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData())->assertOk();

        $this->assertSame(['recordCheckout', 'claimSubscription', 'markSubscriptionProcessed'], $spy->calls);
        $this->assertSame(0, WlcmTransaction::query()->count());
    }

    public function test_swapped_log_store_receives_masked_bodies(): void
    {
        $spy = new class implements LogStore
        {
            /** @var array<string, mixed>|null */
            public ?array $requestBody = null;

            public function out(string $connection, string $api, string $method, string $url, ?array $requestBody, mixed $responseBody, ?int $status, int $durationMs, array $ids = [], string $result = 'ok'): void
            {
                $this->requestBody = $requestBody;
            }

            public function webhook(string $connection, array $payload, string $result): void {}
        };
        $this->app->instance(LogStore::class, $spy);

        Http::fake(['api.wlcm.test/*' => Http::response([
            'card_id' => 7, 'otp_sent_phone' => '**********67', 'reused' => false,
        ], 200)]);

        Wlcm::partner()->subscriptions()->attachCard(5, '9860991234569999', '3003', '998901234567');

        $this->assertIsArray($spy->requestBody);
        $this->assertSame('986099******9999', $spy->requestBody['card_number']);
        $this->assertSame('***', $spy->requestBody['expire_date']);
    }

    public function test_pan_prose_in_a_success_body_is_scrubbed_on_the_log_surface(): void
    {
        $spy = new class implements LogStore
        {
            public mixed $responseBody = null;

            public function out(string $connection, string $api, string $method, string $url, ?array $requestBody, mixed $responseBody, ?int $status, int $durationMs, array $ids = [], string $result = 'ok'): void
            {
                $this->responseBody = $responseBody;
            }

            public function webhook(string $connection, array $payload, string $result): void {}
        };
        $this->app->instance(LogStore::class, $spy);

        Http::fake(['api.wlcm.test/*' => Http::response([
            'status' => 'ok',
            'note' => 'settled via card 8600123456789012',
            'ref' => 8600123456789012,
        ], 200)]);

        Wlcm::partner()->get('/partners/me');

        $body = $spy->responseBody;
        $this->assertIsArray($body);
        $this->assertSame('settled via card 860012***9012', $body['note']);
        $this->assertSame('860012***9012', $body['ref']);
    }

    public function test_pan_prose_in_a_409_body_is_scrubbed_on_the_log_surface(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(
            ['detail' => 'Card 8600123456789012 already settled'], 409,
        )]);

        try {
            Wlcm::partner()->payments()->cancel(172);
            $this->fail('expected ConflictException');
        } catch (ConflictException) {
        }

        $log = WlcmLog::sole();
        $this->assertIsArray($log->response_body);
        $this->assertSame('Card 860012***9012 already settled', $log->response_body['detail']);
    }

    public function test_pan_prose_in_a_422_detail_is_scrubbed_on_the_log_surface(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [
                [
                    'type' => 'value_error',
                    'loc' => ['body', 'card_number'],
                    'msg' => 'Value error, card 8600123456789012 is blocked',
                    'input' => '8600123456789012',
                ],
            ],
        ], 422)]);

        try {
            Wlcm::partner()->checkout()->create(
                amountTiyin: 120000, externalId: 'ORD-9', provider: 'card',
                cardNumber: '8600123456789012', expireDate: '3003',
            );
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
        }

        $log = WlcmLog::sole();
        $this->assertIsArray($log->response_body);
        $detail = $log->response_body['detail'];
        $this->assertIsArray($detail);
        $this->assertIsArray($detail[0]);
        $this->assertSame('Value error, card 860012***9012 is blocked', $detail[0]['msg']);
        $this->assertSame('860012******9012', $detail[0]['input']);
    }

    // The bundled stores drop a non-array response body, but the interface hands it to custom
    // stores verbatim, so it has to leave the client already scrubbed.
    public function test_non_array_bodies_are_scrubbed_on_the_log_surface(): void
    {
        $spy = new class implements LogStore
        {
            /** @var list<mixed> */
            public array $bodies = [];

            public function out(string $connection, string $api, string $method, string $url, ?array $requestBody, mixed $responseBody, ?int $status, int $durationMs, array $ids = [], string $result = 'ok'): void
            {
                $this->bodies[] = $responseBody;
            }

            public function webhook(string $connection, array $payload, string $result): void {}
        };
        $this->app->instance(LogStore::class, $spy);

        Http::fake([
            '*partners/me' => Http::response('8600123456789012', 200),
            '*partners/7' => Http::response('1234567890123456', 200),
            '*partners/9' => Http::response('"upstream refused card 8600123456789012"', 200),
        ]);

        foreach (['/partners/me', '/partners/7', '/partners/9'] as $path) {
            try {
                Wlcm::partner()->get($path);
                $this->fail('expected ServerException');
            } catch (ServerException) {
            }
        }

        $this->assertSame(
            ['860012***9012', 1234567890123456, 'upstream refused card 860012***9012'],
            $spy->bodies,
        );
    }

    public function test_pan_prose_in_a_webhook_field_is_scrubbed_on_the_log_surface(): void
    {
        // external_id sits outside the signature base, so prose there is unauthenticated.
        $data = $this->webhookData();
        $data['external_id'] = 'ORD-1 via card 8600123456789012';

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        $log = WlcmLog::sole();
        $this->assertIsArray($log->request_body);
        $this->assertSame('ORD-1 via card 860012***9012', $log->request_body['external_id']);
        // Correlation keys are taken from the payload, not the log surface.
        $this->assertSame('ORD-1 via card 8600123456789012', WlcmTransaction::sole()->external_id);
    }
}
