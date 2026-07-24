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
}
