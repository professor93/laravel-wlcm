<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
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

    // A typo'd driver must fail loudly at write time too, not silently discard. It
    // already throws at resolve; a value edited afterwards used to just stop writing.
    public function test_an_unreadable_driver_throws_instead_of_discarding(): void
    {
        app(LogStore::class);
        config(['wlcm.storage.logs' => 'databse']);

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.storage.logs');

        $this->checkout();
    }

    private function checkout(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'external_id' => 't-1', 'state' => 1,
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/410', 'message' => null,
            'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
        ], 200)]);

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, externalId: 't-1');
    }
}
