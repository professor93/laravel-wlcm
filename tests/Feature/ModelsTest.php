<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
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

        $fresh = $log->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(['amount' => 1], $fresh->request_body);
    }

    public function test_transaction_row_roundtrip(): void
    {
        $tx = WlcmTransaction::create([
            'connection' => 'main', 'api' => 'partner', 'external_id' => 'ORD-1',
            'order_id' => '1254', 'payment_id' => '7788', 'amount_tiyin' => 120000,
            'provider' => 'payme', 'state' => 2, 'state_text' => 'success', 'meta' => [],
        ]);

        $fresh = $tx->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(120000, $fresh->amount_tiyin);
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

    public function test_null_stale_days_keeps_abandoned_checkouts(): void
    {
        config()->set('wlcm.storage.prune_days', 90);
        config()->set('wlcm.storage.stale_days', null);

        $abandoned = $this->transaction(null, now()->subDays(400));

        $this->prune();

        $this->assertNotNull(WlcmTransaction::find($abandoned->id));
    }

    public function test_stale_days_reaps_abandoned_checkouts(): void
    {
        config()->set('wlcm.storage.prune_days', 90);
        config()->set('wlcm.storage.stale_days', 30);

        $old = $this->transaction(null, now()->subDays(31));
        $fresh = $this->transaction(null, now()->subDays(29));

        $this->prune();

        $this->assertNull(WlcmTransaction::find($old->id));
        $this->assertNotNull(WlcmTransaction::find($fresh->id));
    }

    public function test_claimed_unprocessed_transactions_survive_the_reaper(): void
    {
        config()->set('wlcm.storage.prune_days', 90);
        config()->set('wlcm.storage.stale_days', 30);

        $claimed = $this->transaction('CLAIMED', now()->subDays(400));

        $this->prune();

        $this->assertNotNull(WlcmTransaction::find($claimed->id));
    }

    public function test_unprocessed_subscription_claims_survive_the_reaper(): void
    {
        config()->set('wlcm.storage.prune_days', 90);
        config()->set('wlcm.storage.stale_days', 30);

        $claim = WlcmTransaction::create([
            'connection' => 'main', 'api' => 'subscription', 'external_id' => 'SUB-1',
            'state_text' => 'subscription.charged', 'delivery_key' => 'a3f1',
            'created_at' => now()->subDays(400), 'processed_at' => null,
        ]);

        $this->prune();

        $this->assertNotNull(WlcmTransaction::find($claim->id));
    }

    public function test_non_numeric_stale_days_disables_the_reaper(): void
    {
        config()->set('wlcm.storage.prune_days', null);
        config()->set('wlcm.storage.stale_days', 'ninety');

        $abandoned = $this->transaction(null, now()->subYears(2));

        $this->prune();

        $this->assertNotNull(WlcmTransaction::find($abandoned->id));
    }

    public function test_zero_stale_days_clamps_to_one_day(): void
    {
        config()->set('wlcm.storage.prune_days', null);
        config()->set('wlcm.storage.stale_days', 0);

        $old = $this->transaction(null, now()->subDays(2));
        $fresh = $this->transaction(null, now()->subHours(2));

        $this->prune();

        $this->assertNull(WlcmTransaction::find($old->id));
        $this->assertNotNull(WlcmTransaction::find($fresh->id));
    }

    public function test_negative_stale_days_clamps_to_one_day(): void
    {
        config()->set('wlcm.storage.prune_days', null);
        config()->set('wlcm.storage.stale_days', -5);

        $old = $this->transaction(null, now()->subDays(2));
        $fresh = $this->transaction(null, now()->subHours(2));

        $this->prune();

        $this->assertNull(WlcmTransaction::find($old->id));
        $this->assertNotNull(WlcmTransaction::find($fresh->id));
    }

    public function test_stale_days_alone_leaves_processed_rows_immortal(): void
    {
        config()->set('wlcm.storage.prune_days', null);
        config()->set('wlcm.storage.stale_days', 30);

        $processed = $this->transaction('PROCESSED', now()->subDays(400), now()->subDays(400));
        $abandoned = $this->transaction(null, now()->subDays(31));

        $this->prune();

        $this->assertNotNull(WlcmTransaction::find($processed->id));
        $this->assertNull(WlcmTransaction::find($abandoned->id));
    }

    public function test_prune_days_alone_leaves_abandoned_checkouts_immortal(): void
    {
        config()->set('wlcm.storage.prune_days', 30);
        config()->set('wlcm.storage.stale_days', null);

        $processed = $this->transaction('PROCESSED', now()->subDays(31), now()->subDays(31));
        $abandoned = $this->transaction(null, now()->subDays(400));

        $this->prune();

        $this->assertNull(WlcmTransaction::find($processed->id));
        $this->assertNotNull(WlcmTransaction::find($abandoned->id));
    }

    public function test_both_windows_on_spare_fresh_rows_of_either_kind(): void
    {
        config()->set('wlcm.storage.prune_days', 30);
        config()->set('wlcm.storage.stale_days', 30);

        $oldProcessed = $this->transaction('OLD-PROCESSED', now()->subDays(31), now()->subDays(31));
        $freshProcessed = $this->transaction('FRESH-PROCESSED', now()->subDays(1), now()->subDays(1));
        $oldAbandoned = $this->transaction(null, now()->subDays(31));
        $freshAbandoned = $this->transaction(null, now()->subDays(1));

        $this->prune();

        $this->assertNull(WlcmTransaction::find($oldProcessed->id));
        $this->assertNotNull(WlcmTransaction::find($freshProcessed->id));
        $this->assertNull(WlcmTransaction::find($oldAbandoned->id));
        $this->assertNotNull(WlcmTransaction::find($freshAbandoned->id));
    }

    private function prune(): void
    {
        $command = $this->artisan('model:prune', ['--model' => [WlcmLog::class, WlcmTransaction::class]]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0);
    }

    private function log(Carbon $createdAt): WlcmLog
    {
        return WlcmLog::create([
            'connection' => 'main', 'direction' => 'out', 'created_at' => $createdAt,
        ]);
    }

    private function transaction(?string $paymentId, Carbon $createdAt, ?Carbon $processedAt = null): WlcmTransaction
    {
        return WlcmTransaction::create([
            'connection' => 'main', 'api' => 'partner', 'payment_id' => $paymentId,
            'amount_tiyin' => 100, 'created_at' => $createdAt, 'processed_at' => $processedAt,
        ]);
    }
}
