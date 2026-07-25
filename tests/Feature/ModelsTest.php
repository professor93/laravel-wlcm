<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $old = WlcmTransaction::create([
            'connection' => 'main', 'api' => 'partner', 'payment_id' => 'OLD',
            'amount_tiyin' => 100, 'created_at' => now()->subDays(31),
        ]);
        $fresh = WlcmTransaction::create([
            'connection' => 'main', 'api' => 'partner', 'payment_id' => 'FRESH',
            'amount_tiyin' => 100, 'created_at' => now()->subDays(29),
        ]);

        $this->artisan('model:prune', ['--model' => [WlcmTransaction::class]])->assertExitCode(0);

        $this->assertNull(WlcmTransaction::find($old->id));
        $this->assertNotNull(WlcmTransaction::find($fresh->id));
    }
}
