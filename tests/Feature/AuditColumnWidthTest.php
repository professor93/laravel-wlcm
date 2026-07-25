<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\DatabaseLogStore;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookOutcome;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class AuditColumnWidthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Widths as the shipped migrations declare them.
     *
     * @var array<string, array<string, int>>
     */
    private const array WIDTHS = [
        'wlcm_logs' => ['external_id' => 100, 'order_id' => 64, 'payment_id' => 64, 'transaction_id' => 64],
        'wlcm_transactions' => ['external_id' => 100, 'order_id' => 64, 'payment_id' => 64, 'transaction_id' => 64, 'state_text' => 32],
    ];

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforceDeclaredWidths();
    }

    // Sqlite ignores varchar widths, mysql/pgsql reject the row. Triggers put the
    // production behaviour back so the swallowed write is visible here.
    private function enforceDeclaredWidths(): void
    {
        foreach (self::WIDTHS as $table => $columns) {
            foreach ($columns as $column => $width) {
                foreach (['INSERT', 'UPDATE'] as $event) {
                    DB::statement(
                        "CREATE TRIGGER {$table}_{$column}_".strtolower($event)."_width BEFORE {$event} ON {$table} ".
                        "FOR EACH ROW WHEN length(NEW.{$column}) > {$width} ".
                        "BEGIN SELECT RAISE(ABORT, 'value too long for {$table}.{$column}'); END",
                    );
                }
            }
        }
    }

    public function test_a_checkout_with_an_over_long_external_id_still_leaves_both_audit_rows(): void
    {
        $externalId = str_pad('REPRO-', 120, 'X');
        Exceptions::fake();
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => $externalId, 'state' => 1,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);

        $response = Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: $externalId,
        );

        $this->assertSame($externalId, $response->externalId);
        $this->assertSame(1, WlcmLog::query()->count());
        $this->assertSame(1, WlcmTransaction::query()->count());
        Exceptions::assertNothingReported();

        $fitted = str_pad('REPRO-', 99, 'X').'~';
        $this->assertSame($fitted, WlcmLog::sole()->external_id);
        $this->assertSame($fitted, WlcmTransaction::sole()->external_id);

        // Cut column keeps a marker; the untouched value stays in the body json.
        $log = WlcmLog::sole();
        $this->assertSame($externalId, $log->request_body['external_id'] ?? null);
        $this->assertSame($externalId, $log->response_body['external_id'] ?? null);
    }

    public function test_an_external_id_at_the_column_width_is_written_untouched(): void
    {
        $externalId = str_pad('EXACT-', 100, 'X');
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => $externalId, 'state' => 1,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);

        Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: $externalId,
        );

        $this->assertSame($externalId, WlcmLog::sole()->external_id);
        $this->assertSame($externalId, WlcmTransaction::sole()->external_id);
    }

    public function test_over_long_wire_ids_fit_the_narrower_log_columns(): void
    {
        $long = str_pad('ID-', 80, 'Y');
        Exceptions::fake();
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => $long, 'payment_id' => $long, 'transaction_id' => $long,
        ], 200)]);

        Wlcm::partner()->get('/orders/1/status');

        $log = WlcmLog::sole();
        $fitted = str_pad('ID-', 63, 'Y').'~';
        $this->assertSame($fitted, $log->order_id);
        $this->assertSame($fitted, $log->payment_id);
        $this->assertSame($fitted, $log->transaction_id);
        $this->assertSame($long, $log->response_body['order_id'] ?? null);
        Exceptions::assertNothingReported();
    }

    public function test_an_over_long_webhook_row_is_recorded_and_stays_findable(): void
    {
        $externalId = str_pad('WH-', 120, 'Z');
        $paymentId = str_pad('PAY-', 80, 'Z');
        $payload = WebhookPayload::fromArray([
            'external_id' => $externalId,
            'order_id' => str_pad('ORD-', 80, 'Z'),
            'payment_id' => $paymentId,
            'amount' => '1200',
            'state' => '2',
            'provider' => 'payme',
            'timestamp' => '1750000000',
        ], 'main');

        $recorder = app(TransactionStore::class);
        $this->assertSame(WebhookOutcome::Fresh, $recorder->applyWebhook($payload));

        $row = WlcmTransaction::sole();
        $this->assertSame(str_pad('WH-', 99, 'Z').'~', $row->external_id);
        $this->assertSame(str_pad('PAY-', 63, 'Z').'~', $row->payment_id);

        // A cut key still has to find its own row back, or nothing is ever marked done.
        $recorder->markProcessed($payload);
        $this->assertNotNull(WlcmTransaction::sole()->processed_at);
        $this->assertSame(WebhookOutcome::Duplicate, $recorder->applyWebhook($payload));

        (new DatabaseLogStore)->webhook('main', [
            'external_id' => $externalId, 'order_id' => str_pad('ORD-', 80, 'Z'), 'payment_id' => $paymentId,
        ], 'ok');
        $this->assertSame(str_pad('WH-', 99, 'Z').'~', WlcmLog::sole()->external_id);
    }

    public function test_an_over_long_subscription_claim_is_recorded_and_deduplicates(): void
    {
        $payload = SubscriptionWebhookPayload::fromArray([
            'event' => 'subscription.charge.failed.retry.scheduled.tomorrow',
            'external_order_id' => str_pad('SUB-', 120, 'Q'),
            'subscription_id' => 9,
            'timestamp' => '1750000000',
        ], 'main');

        $recorder = app(TransactionStore::class);
        $this->assertSame(WebhookOutcome::Fresh, $recorder->claimSubscription($payload));
        $this->assertSame(str_pad('SUB-', 99, 'Q').'~', WlcmTransaction::sole()->external_id);

        $recorder->markSubscriptionProcessed($payload);
        $this->assertSame(WebhookOutcome::Duplicate, $recorder->claimSubscription($payload));
        $this->assertSame(1, WlcmTransaction::query()->count());
    }
}
