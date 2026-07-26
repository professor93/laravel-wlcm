<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class WebhookConcurrencyTest extends TestCase
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

    private function payload(string $state, string $paymentId = '7788'): WebhookPayload
    {
        return WebhookPayload::fromArray([
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => $paymentId,
            'amount' => '1200.00', 'state' => $state, 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ], 'main');
    }

    /**
     * A slow dispatch must not stamp a row that has moved on to a newer delivery.
     * Otherwise the newer delivery's own redelivery reads as processed and its event
     * never fires again — a payment notification lost with no error anywhere.
     */
    public function test_a_late_finisher_cannot_stamp_the_claim_that_replaced_it(): void
    {
        $store = app(TransactionStore::class);

        // D1 claims and begins dispatching; its handler is slow.
        $store->applyWebhook($this->payload('1'), 'tok-a');

        // D2 (the success) arrives mid-flight, legitimately transitions the row, and
        // begins its own dispatch.
        $store->applyWebhook($this->payload('2'), 'tok-b');

        // D1's handler finally returns and stamps what is now D2's row.
        $store->markProcessed($this->payload('1'), 'tok-a');

        $this->assertNull(
            WlcmTransaction::sole()->processed_at,
            'a finished dispatch stamped a claim that belongs to a later delivery',
        );
    }
}
