<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookOutcome;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

/**
 * Only merchant free-text carries pans. Server-minted ids cannot, so scrubbing them
 * buys nothing and costs correlation: the scrub is lossy and two distinct ids can
 * land on one stored value.
 */
final class ServerIdFidelityTest extends TestCase
{
    use RefreshDatabase;

    // Distinct, both luhn-passing, identical first six and last four.
    private const string PAYMENT_A = '4111110000091111';

    private const string PAYMENT_B = '4111110000171111';

    // Live shape from the sandbox, with an ms epoch that happens to pass luhn.
    private const string TRANSACTION_ID = 'mock_tx_1784911325122';

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
    private function webhookData(string $paymentId, string $externalId = 'ORD-1'): array
    {
        $data = [
            'external_id' => $externalId, 'order_id' => '1254', 'payment_id' => $paymentId,
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:{$paymentId}:2:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    /**
     * `event` rides outside the payment signature base, so one captured payment signature
     * plus `"event": ""` costs an attacker nothing. The middleware routes that as a
     * PAYMENT (empty means absent) while WebhookLogIds used `isset()`, which is true for
     * `''`, and routed it as a SUBSCRIPTION — reading an absent `external_order_id` and
     * nulling the audit row's correlation column. Two detectors, two answers, one
     * delivery.
     */
    public function test_an_empty_event_cannot_null_the_audit_correlation(): void
    {
        Event::fake();
        $data = $this->webhookData(self::PAYMENT_A, 'ORD-KEEPME');
        $data['event'] = '';

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        $this->assertSame('ORD-KEEPME', WlcmLog::sole()->external_id, 'an empty event nulled the audit correlation');
        $this->assertSame('ORD-KEEPME', WlcmTransaction::sole()->external_id);
    }

    /**
     * A malformed amount that is not negative throws out of `Tiyin::fromSumString` inside
     * the claim, before any log write and outside the controller's try. The delivery 500s
     * and leaves NO trace at all — and WLCM does not retry. The missing audit row is the
     * defect more than the status code, so that is what this asserts.
     *
     * @return array<string, array{string}>
     */
    public static function malformedAmounts(): array
    {
        return [
            'three decimals' => ['10.000'],
            'thousands separator' => ['1,000.00'],
            'seventeen digit whole' => ['12345678901234567.00'],
            'exponent' => ['1.0E+25'],
            'not a number' => ['abc'],
        ];
    }

    #[DataProvider('malformedAmounts')]
    public function test_a_malformed_amount_is_refused_with_an_audit_trail(string $amount): void
    {
        Event::fake();
        $data = $this->webhookData(self::PAYMENT_A);
        $data['amount'] = $amount;

        $this->postJson('/wlcm/webhook', $data)->assertStatus(422);

        Event::assertNotDispatched(PaymentSucceeded::class);
        $this->assertSame(0, WlcmTransaction::query()->count(), 'a refused delivery left a claim row');
    }

    public function test_two_luhn_coincident_payment_ids_stay_two_transactions(): void
    {
        $recorder = app(TransactionStore::class);

        foreach ([self::PAYMENT_A, self::PAYMENT_B] as $paymentId) {
            $outcome = $recorder->applyWebhook(WebhookPayload::fromArray([
                'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => $paymentId,
                'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
                'timestamp' => '2026-07-24 12:00:00',
            ], 'main'), 'tok-'.$paymentId);

            $this->assertSame(WebhookOutcome::Fresh, $outcome);
        }

        // One scrubbed value for both ids would merge two real payments into one row.
        $this->assertSame(2, WlcmTransaction::query()->count());
        $this->assertSame(
            [self::PAYMENT_A, self::PAYMENT_B],
            WlcmTransaction::query()->orderBy('id')->pluck('payment_id')->all(),
        );
    }

    public function test_a_luhn_coincident_transaction_id_survives_the_checkout_record(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-9', 'state' => 1,
            'checkout_url' => 'https://c.test/x', 'transaction_id' => self::TRANSACTION_ID,
        ], 201)]);

        Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9',
        );

        $this->assertSame(self::TRANSACTION_ID, WlcmTransaction::sole()->transaction_id);
        $this->assertSame(self::TRANSACTION_ID, WlcmLog::sole()->transaction_id);
    }

    public function test_the_two_tables_agree_on_a_luhn_coincident_server_id(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->webhookData(self::PAYMENT_A))->assertOk();

        $log = WlcmLog::sole();
        $tx = WlcmTransaction::sole();

        // The join the audit trail is built on: raw on both sides, therefore equal.
        $this->assertSame(self::PAYMENT_A, $tx->payment_id);
        $this->assertSame(self::PAYMENT_A, $log->payment_id);
        $this->assertSame('1254', $log->order_id);
        $this->assertSame('1254', $tx->order_id);
    }

    public function test_merchant_reference_is_still_scrubbed_on_both_surfaces(): void
    {
        Event::fake();
        $pan = '4111111111111111';

        $this->postJson('/wlcm/webhook', $this->webhookData(self::PAYMENT_A, 'ref for card '.$pan))->assertOk();

        $log = WlcmLog::sole();
        $tx = WlcmTransaction::sole();

        $this->assertSame('ref for card 411111***1111', $tx->external_id);
        $this->assertSame($log->external_id, $tx->external_id);
        // Blob keeps its whole-body scrub regardless.
        $this->assertIsArray($log->request_body);
        $this->assertSame('ref for card 411111***1111', $log->request_body['external_id']);
        $this->assertStringNotContainsString($pan, (string) json_encode($log->toArray()));
        $this->assertStringNotContainsString($pan, (string) json_encode($tx->toArray()));
    }

    public function test_outbound_log_keeps_server_ids_and_scrubs_the_reference(): void
    {
        $pan = '4111111111111111';
        Http::fake(['api.wlcm.test/*' => Http::response([
            'external_id' => 'ref for card '.$pan,
            'order_id' => self::PAYMENT_A,
            'payment_id' => self::PAYMENT_B,
            'transaction_id' => self::TRANSACTION_ID,
        ], 200)]);

        Wlcm::partner()->get('/orders/1/status');

        $log = WlcmLog::sole();
        $this->assertSame('ref for card 411111***1111', $log->external_id);
        $this->assertSame(self::PAYMENT_A, $log->order_id);
        $this->assertSame(self::PAYMENT_B, $log->payment_id);
        $this->assertSame(self::TRANSACTION_ID, $log->transaction_id);
    }
}
