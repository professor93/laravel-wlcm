<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\ColumnFit;
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

    /**
     * ColumnFit hand-copies the migration widths, and sqlite drops varchar lengths
     * entirely — Schema::getColumns reports a bare `varchar` — so the shipped migration
     * source is the only offline truth to compare against. Edit a width there and this
     * fails instead of silently letting the fitter cut to a stale number.
     */
    public function test_the_width_map_matches_the_shipped_migrations(): void
    {
        $declared = [];

        foreach ((array) glob(__DIR__.'/../../database/migrations/*.php') as $file) {
            preg_match_all(
                "/->string\('([a-z_]+)',\s*(\d+)\)/",
                (string) file_get_contents((string) $file),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $column = $match[1];
                $width = (int) $match[2];

                // ColumnFit keys by column name alone, so one name must mean one width.
                if (isset($declared[$column]) && $declared[$column] !== $width) {
                    $this->fail("column {$column} is declared at two widths; ColumnFit cannot represent that");
                }

                $declared[$column] = $width;
            }
        }

        // Columns deliberately left unfitted, with the reason. Declared here rather than
        // silently absent, so forgetting a NEW narrow column still fails this test.
        // dispatch_token: package-minted and fixed width — a cut token would silently
        // fail to match on release and strand the claim until the lease expires.
        $unfitted = ['dispatch_token'];

        foreach ($unfitted as $column) {
            $this->assertArrayHasKey($column, $declared, "{$column} is excused from ColumnFit but no longer exists");
            unset($declared[$column]);
        }

        $widths = (new ReflectionClass(ColumnFit::class))->getConstant('WIDTHS');
        $this->assertIsArray($widths);
        ksort($declared);
        ksort($widths);

        $this->assertSame($declared, $widths);
    }

    /**
     * Postgres truncates a text value at the first NUL byte and stores the head with no
     * error; mysql stores the whole thing. Same package, same delivery, two different
     * stored ids — and on `order_id` that silently changes what the row correlates to.
     * Stripping makes every engine agree.
     */
    /**
     * Only values were stripped, and a signed delivery may carry any extra KEY it likes —
     * the base covers four fields. On pgsql a NUL in a key makes `request_body::jsonb`,
     * `->>` and `json_each_text` all raise 22P05, the exact error the strip prevents.
     */
    public function test_a_nul_byte_in_an_object_key_cannot_reach_the_blob(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'state' => 1, "note\0hidden" => 'x',
        ], 201)]);

        Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9',
        );

        $encoded = (string) json_encode(WlcmLog::sole()->response_body);
        $this->assertStringNotContainsString('\u0000', $encoded, 'a NUL survived in an object key');
        $this->assertStringNotContainsString("\0", $encoded);
    }

    public function test_a_nul_byte_in_an_id_cannot_split_the_value(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => "NUL-HEAD-\0-tail", 'state' => 1,
            'checkout_url' => 'https://c.test/x',
        ], 201)]);

        Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: "NUL-HEAD-\0-tail",
        );

        foreach ([WlcmLog::sole()->external_id, WlcmTransaction::sole()->external_id] as $stored) {
            $this->assertStringNotContainsString("\0", (string) $stored, 'a NUL byte reached a stored id');
            // The tail must survive, not be silently discarded with the separator.
            $this->assertStringContainsString('tail', (string) $stored);
        }
    }

    /**
     * Mysql rejects a json document over 100 levels deep and the audit write is swallowed,
     * so a delivery that dispatched and fired its events left NO log row — while postgres
     * stored the same body without complaint. Payload shape alone decided whether a
     * dispatched delivery was auditable.
     */
    public function test_a_very_deep_body_still_lands_an_audit_row(): void
    {
        $deep = 'leaf';

        for ($i = 0; $i < 200; $i++) {
            $deep = ['n' => $deep];
        }

        Http::fake(['api.wlcm.test/*' => Http::response(['order_id' => 1254, 'state' => 1, 'deep' => $deep], 201)]);
        Exceptions::fake();

        Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9',
        );

        $log = WlcmLog::sole();
        $this->assertIsArray($log->response_body);
        $this->assertSame(['reshaped' => 'depth'], $log->response_body['_wlcm'] ?? null, 'the reshape was not recorded');
        // Nothing discarded — the whole body is still there, encoded.
        $encoded = $log->response_body['body'] ?? '';
        $this->assertIsString($encoded);
        $this->assertStringContainsString('leaf', $encoded);
        Exceptions::assertNothingReported();
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
        $this->assertSame(WebhookOutcome::Fresh, $recorder->applyWebhook($payload, 'tok'));

        $row = WlcmTransaction::sole();
        $this->assertSame(str_pad('WH-', 99, 'Z').'~', $row->external_id);
        $this->assertSame(str_pad('PAY-', 63, 'Z').'~', $row->payment_id);

        // A cut key still has to find its own row back, or nothing is ever marked done.
        $recorder->markProcessed($payload, 'tok');
        $this->assertNotNull(WlcmTransaction::sole()->processed_at);
        $this->assertSame(WebhookOutcome::Duplicate, $recorder->applyWebhook($payload, 'tok'));

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
        $this->assertSame(WebhookOutcome::Fresh, $recorder->claimSubscription($payload, 'tok'));
        $this->assertSame(str_pad('SUB-', 99, 'Q').'~', WlcmTransaction::sole()->external_id);

        $recorder->markSubscriptionProcessed($payload, 'tok');
        $this->assertSame(WebhookOutcome::Duplicate, $recorder->claimSubscription($payload, 'tok'));
        $this->assertSame(1, WlcmTransaction::query()->count());
    }
}
