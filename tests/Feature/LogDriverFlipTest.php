<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Storage\DatabaseLogStore;
use Uzbek\Wlcm\Storage\FileLogStore;
use Uzbek\Wlcm\Storage\LogChannelLogStore;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Tests\TestCase;

/**
 * Changing `wlcm.storage.logs` from one driver to another inside a long-lived process
 * (Octane, a queue worker, any warm container) sent the entry NOWHERE: the resolved
 * singleton asked "am I the configured driver", answered no, and the newly named driver
 * was never constructed. Zero rows, zero bytes, no error, no report(), delivery still 200.
 *
 * The driver being fixed at first resolve is documented and deliberate. Silently
 * discarding the audit trail was not.
 */
final class LogDriverFlipTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('wlcm.storage.logs', 'database');
        $app['config']->set('wlcm.storage.transactions', true);
        $app['config']->set('wlcm.webhook.tolerance_seconds', null);
    }

    /**
     * Every value the provider accepts as a live driver. Reported only against
     * database->file; all three drivers asked the same wrong question, so the flip is
     * enumerated over the whole accepted set rather than the one pair that was seen.
     *
     * @return array<string, array{string|bool}>
     */
    public static function otherDrivers(): array
    {
        return [
            'to file' => ['file'],
            'to log' => ['log'],
            'to database' => ['database'],
            'to true' => [true],
            'to one' => ['1'],
        ];
    }

    #[DataProvider('otherDrivers')]
    public function test_a_driver_flip_keeps_writing_on_the_resolved_driver(string|bool $driver): void
    {
        // Resolve while 'database', which fixes the driver for this process.
        app(LogStore::class);
        config(['wlcm.storage.logs' => $driver]);

        $this->checkout();

        $this->assertSame(1, WlcmLog::query()->count(), 'the audit entry went nowhere');
    }

    /** @return array<string, array{mixed}> */
    public static function offValues(): array
    {
        return [
            'false' => [false],
            'zero string' => ['0'],
            'null' => [null],
            'empty' => [''],
        ];
    }

    // The other half. Off must still mean off, or this fix would have turned the toggle
    // into decoration.
    #[DataProvider('offValues')]
    public function test_turning_logging_off_at_runtime_still_stops_writes(mixed $off): void
    {
        app(LogStore::class);
        config(['wlcm.storage.logs' => $off]);

        $this->checkout();

        $this->assertSame(0, WlcmLog::query()->count(), 'a disabled sink still wrote');
    }

    /**
     * INVERTED DELIBERATELY. An earlier version of this test asserted that a driver value
     * poisoned at runtime THROWS from the write path. That was wrong, and wrong in the
     * most expensive direction: `enabled()` is consulted before the store's try/catch, so
     * the throw escaped into the caller — destroying a `checkout()` whose HTTP round trip
     * to WLCM had already SUCCEEDED. The merchant then cannot tell "payment failed" from
     * "payment succeeded, audit config broken", which is the one distinction that matters.
     *
     * It also contradicted the stores' own stated invariant: audit writes never break a
     * payment call.
     *
     * Failing closed still happens where it is safe — the provider refuses to resolve an
     * unreadable driver at all, so a typo present at boot is loud. This covers only the
     * post-resolution case: report it, drop the entry, let the payment stand.
     */
    public function test_a_driver_poisoned_after_resolution_reports_and_lets_the_payment_stand(): void
    {
        Exceptions::fake();
        app(LogStore::class);
        config(['wlcm.storage.logs' => 'databse']);

        $response = null;

        try {
            $response = $this->checkoutResponse();
        } catch (Throwable $e) {
            $this->fail('a poisoned audit config destroyed a completed payment call: '.$e::class);
        }

        $this->assertSame(410, $response->orderId, 'the payment result was lost');
        $this->assertSame(0, WlcmLog::query()->count(), 'a poisoned driver still wrote');
        Exceptions::assertReported(MissingConfigurationException::class);
    }

    /**
     * @return array<string, array{class-string<LogStore>}>
     *
     * All three drivers, because the test above only ever instantiates the one that was
     * configured at resolve time — reverting the guard in `FileLogStore` or
     * `LogChannelLogStore` failed nothing until this existed. Measured, not assumed.
     */
    public static function everyDriver(): array
    {
        return [
            'database' => [DatabaseLogStore::class],
            'file' => [FileLogStore::class],
            'log channel' => [LogChannelLogStore::class],
        ];
    }

    /**
     * OUTBOUND, every driver: must not throw. Split from the inbound case below after that
     * one turned out to have the opposite requirement — an earlier version of this test
     * asserted BOTH paths stay quiet, which was pinning a money-path defect.
     *
     * @param  class-string<LogStore>  $driver
     */
    #[DataProvider('everyDriver')]
    public function test_no_driver_throws_out_of_an_outbound_write(string $driver): void
    {
        Exceptions::fake();
        config(['wlcm.storage.logs' => 'databse']);

        (new $driver)->out('main', 'partner', 'POST', 'https://api.wlcm.test/x', null, null, 200, 1);

        $this->assertSame(0, WlcmLog::query()->count());
        Exceptions::assertReported(MissingConfigurationException::class);
    }

    /**
     * INBOUND, every driver: must throw, so the controller can release the claim and let
     * WLCM redeliver rather than dispatching a money event nobody can audit.
     *
     * @param  class-string<LogStore>  $driver
     */
    #[DataProvider('everyDriver')]
    public function test_every_driver_throws_out_of_a_webhook_write(string $driver): void
    {
        config(['wlcm.storage.logs' => 'databse']);

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.storage.logs');

        (new $driver)->webhook('main', ['order_id' => '1'], 'ok');
    }

    // Boot-time is still fail-closed: the provider will not resolve an unreadable driver.
    public function test_an_unreadable_driver_still_refuses_to_resolve(): void
    {
        config(['wlcm.storage.logs' => 'databse']);

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.storage.logs');

        app(LogStore::class);
    }

    private function checkout(): void
    {
        $this->checkoutResponse();
    }

    private function checkoutResponse(): CheckoutResponse
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'external_id' => 't-1', 'state' => 1,
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/410', 'message' => null,
            'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
        ], 200)]);

        return Wlcm::partner()->checkout()->create(amountTiyin: 120000, externalId: 't-1');
    }

    /**
     * THE INBOUND HALF, and the reason `enabled()` cannot be one shared method.
     *
     * Report-and-drop is right OUTBOUND: the payment already completed at WLCM, so throwing
     * would destroy a call whose money moved. Applying the same rule INBOUND inverts it —
     * nothing has happened upstream yet, and a 500 is simply a redelivery. The controller
     * writes the audit row FIRST inside its try, so a throw there releases the claim and
     * WLCM tries again; swallowing instead fires WebhookReceived + PaymentSucceeded, stamps
     * processed_at, and answers 200 with ZERO audit rows for a dispatched money event.
     *
     * That is the exact state `34af392` exists to prevent, reached through another door, and
     * it adds an undocumented fourth possibility to the audit contract — README says an `ok`
     * with no successor means a worker died mid-dispatch, and "nothing at all" was not on
     * the list.
     */
    public function test_a_poisoned_config_never_dispatches_a_money_event_unaudited(): void
    {
        Event::fake();
        app(LogStore::class);
        config(['wlcm.storage.logs' => 'databse']);

        $this->postJson('/wlcm/webhook', $this->signedWebhook())->assertStatus(500);

        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
        $this->assertSame(0, WlcmLog::query()->count());
        // Claim released, so WLCM's redelivery can still be handled once the config is fixed.
        $this->assertNull(WlcmTransaction::sole()->processed_at, 'the claim was stamped despite no audit row');
        $this->assertNull(WlcmTransaction::sole()->dispatch_token, 'the claim was not released');
    }

    /** @return array<string, string> */
    private function signedWebhook(): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', '1254:7788:2:2026-07-24 12:00:00', 'whsec_test');

        return $data;
    }
}
