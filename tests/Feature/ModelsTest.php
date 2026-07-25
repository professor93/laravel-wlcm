<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Tests\TestCase;

final class ModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_log_row_roundtrip(): void
    {
        $log = WlcmLog::create([
            'connection' => 'main', 'direction' => 'out', 'api' => 'partner',
            'method' => 'POST', 'path' => '/integrations/checkout',
            'request_body' => ['amount' => 1], 'response_body' => ['ok' => true],
            'status_code' => 201, 'duration_ms' => 120, 'result' => 'ok',
        ]);

        $this->assertSame(['amount' => 1], $log->fresh()->request_body);
    }

    public function test_transaction_row_roundtrip(): void
    {
        $tx = WlcmTransaction::create([
            'connection' => 'main', 'api' => 'partner', 'external_id' => 'ORD-1',
            'order_id' => '1254', 'payment_id' => '7788', 'amount_tiyin' => 120000,
            'provider' => 'payme', 'state' => 2, 'state_text' => 'success', 'meta' => [],
        ]);

        $this->assertSame(120000, $tx->fresh()->amount_tiyin);
    }

    public function test_prune_drops_old_transactions_and_keeps_fresh(): void
    {
        config()->set('wlcm.storage.prune_days', 30);

        $old = $this->transaction('OLD', now()->subDays(31), now()->subDays(31));
        $fresh = $this->transaction('FRESH', now()->subDays(29), now()->subDays(29));

        $this->prune();

        $this->assertNull(WlcmTransaction::find($old->id));
        $this->assertNotNull(WlcmTransaction::find($fresh->id));
    }

    public function test_null_prune_days_disables_pruning(): void
    {
        config()->set('wlcm.storage.prune_days', null);

        $log = $this->log(now()->subYears(2));
        $tx = $this->transaction('OLD', now()->subYears(2), now()->subYears(2));

        $this->prune();

        $this->assertNotNull(WlcmLog::find($log->id));
        $this->assertNotNull(WlcmTransaction::find($tx->id));
    }

    public function test_non_numeric_prune_days_disables_pruning(): void
    {
        config()->set('wlcm.storage.prune_days', 'ninety');

        $log = $this->log(now()->subYears(2));
        $tx = $this->transaction('OLD', now()->subYears(2), now()->subYears(2));

        $this->prune();

        $this->assertNotNull(WlcmLog::find($log->id));
        $this->assertNotNull(WlcmTransaction::find($tx->id));
    }

    public function test_zero_prune_days_clamps_to_one_day(): void
    {
        config()->set('wlcm.storage.prune_days', 0);

        $oldLog = $this->log(now()->subDays(2));
        $freshLog = $this->log(now()->subHours(2));
        $oldTx = $this->transaction('OLD', now()->subDays(2), now()->subDays(2));
        $freshTx = $this->transaction('FRESH', now()->subHours(2), now()->subHours(2));

        $this->prune();

        $this->assertNull(WlcmLog::find($oldLog->id));
        $this->assertNotNull(WlcmLog::find($freshLog->id));
        $this->assertNull(WlcmTransaction::find($oldTx->id));
        $this->assertNotNull(WlcmTransaction::find($freshTx->id));
    }

    public function test_unprocessed_transactions_are_never_pruned(): void
    {
        config()->set('wlcm.storage.prune_days', 30);

        $inFlight = $this->transaction('IN-FLIGHT', now()->subDays(31));
        $processed = $this->transaction('PROCESSED', now()->subDays(31), now()->subDays(31));

        $this->prune();

        $this->assertNotNull(WlcmTransaction::find($inFlight->id));
        $this->assertNull(WlcmTransaction::find($processed->id));
    }

    private function prune(): void
    {
        $this->artisan('model:prune', ['--model' => [WlcmLog::class, WlcmTransaction::class]])->assertExitCode(0);
    }

    private function log(Carbon $createdAt): WlcmLog
    {
        return WlcmLog::create([
            'connection' => 'main', 'direction' => 'out', 'created_at' => $createdAt,
        ]);
    }

    private function transaction(string $paymentId, Carbon $createdAt, ?Carbon $processedAt = null): WlcmTransaction
    {
        return WlcmTransaction::create([
            'connection' => 'main', 'api' => 'partner', 'payment_id' => $paymentId,
            'amount_tiyin' => 100, 'created_at' => $createdAt, 'processed_at' => $processedAt,
        ]);
    }
}
