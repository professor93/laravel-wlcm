<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookOutcome;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

/**
 * Every classification read used to be forced onto the write connection for free:
 * Connection::getReadPdo() hands back the write pdo while a transaction is open, and
 * Builder::lock() calls useWritePdo(). Dropping DB::transaction and lockForUpdate
 * removed both, so on a read/write split these reads can land on a lagging replica.
 *
 * A real replica cannot be stood up in-suite, so this uses two separate sqlite files —
 * the write file gets the schema and every row, the read file gets the schema only.
 * That is a replica lagging infinitely: strictly harsher than production, and it makes
 * the pdo choice observable rather than mocked.
 */
final class ReadReplicaClaimTest extends TestCase
{
    private string $writePath = '';

    private string $readPath = '';

    protected function setUp(): void
    {
        $this->writePath = tempnam(sys_get_temp_dir(), 'wlcm-w').'.sqlite';
        $this->readPath = tempnam(sys_get_temp_dir(), 'wlcm-r').'.sqlite';
        touch($this->writePath);
        touch($this->readPath);

        parent::setUp();

        Artisan::call('migrate', ['--path' => __DIR__.'/../../database/migrations', '--realpath' => true]);
        // Schema on the replica, no rows — and nothing written after this point reaches it.
        copy($this->writePath, $this->readPath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ([$this->writePath, $this->readPath] as $path) {
            if ($path !== '' && file_exists($path)) {
                unlink($path);
            }
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('wlcm.storage.transactions', true);
        $app['config']->set('database.default', 'wlcm_split');
        $app['config']->set('database.connections.wlcm_split', [
            'driver' => 'sqlite',
            'database' => $this->writePath,
            'read' => ['database' => $this->readPath],
            'write' => ['database' => $this->writePath],
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    private function payload(string $state = '2'): WebhookPayload
    {
        return WebhookPayload::fromArray([
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => $state, 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ], 'main');
    }

    private function subscription(): SubscriptionWebhookPayload
    {
        return SubscriptionWebhookPayload::fromArray([
            'event' => 'subscription.charged', 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ], 'main');
    }

    public function test_the_replica_never_sees_the_write(): void
    {
        // Guards the harness itself: if this ever fails the split stopped being a split
        // and every other assertion here becomes vacuous.
        app(TransactionStore::class)->applyWebhook($this->payload(), 'tok-a');

        $this->assertSame(1, WlcmTransaction::query()->useWritePdo()->count());
        $this->assertSame(0, WlcmTransaction::query()->count(), 'the read connection saw a write');
    }

    public function test_a_partner_claim_classifies_off_the_write_connection(): void
    {
        $store = app(TransactionStore::class);
        $store->applyWebhook($this->payload(), 'tok-a');

        // Reading the row off the replica finds nothing, sends this down the insert path,
        // and the unique index rejects it — three times, then a 500 on a delivery whose
        // real verdict is simply InFlight.
        $this->assertSame(WebhookOutcome::InFlight, $store->applyWebhook($this->payload(), 'tok-b'));
    }

    public function test_a_processed_partner_delivery_is_a_duplicate_not_a_500(): void
    {
        $store = app(TransactionStore::class);
        $store->applyWebhook($this->payload(), 'tok-a');
        $store->markProcessed($this->payload(), 'tok-a');

        $this->assertSame(WebhookOutcome::Duplicate, $store->applyWebhook($this->payload(), 'tok-b'));
    }

    public function test_a_subscription_claim_classifies_off_the_write_connection(): void
    {
        $store = app(TransactionStore::class);
        $store->claimSubscription($this->subscription(), 'tok-a');

        $this->assertSame(WebhookOutcome::InFlight, $store->claimSubscription($this->subscription(), 'tok-b'));
    }
}
