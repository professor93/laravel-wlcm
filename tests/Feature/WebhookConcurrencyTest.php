<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookOutcome;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;
use Uzbek\Wlcm\Webhooks\WlcmWebhookHandler;

final class WebhookConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    /** @return array<string, string> */
    private function signed(string $state, string $paymentId = '7788'): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => $paymentId,
            'amount' => '1200.00', 'state' => $state, 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:{$paymentId}:{$state}:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    private function subscription(string $event = 'subscription.charged'): SubscriptionWebhookPayload
    {
        return SubscriptionWebhookPayload::fromArray([
            'event' => $event, 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
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

        // And the row that D2 still owns must not read as already handled.
        $this->assertSame(WebhookOutcome::InFlight, $store->applyWebhook($this->payload('2'), 'tok-c'));
    }

    public function test_a_partner_delivery_already_being_dispatched_is_not_fresh(): void
    {
        $store = app(TransactionStore::class);

        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('2'), 'tok-a'));

        // No markProcessed: the first dispatch is still inside the handler.
        $this->assertSame(WebhookOutcome::InFlight, $store->applyWebhook($this->payload('2'), 'tok-b'));
    }

    public function test_a_subscription_delivery_already_being_dispatched_is_not_fresh(): void
    {
        $store = app(TransactionStore::class);

        $this->assertSame(WebhookOutcome::Fresh, $store->claimSubscription($this->subscription(), 'tok-a'));
        $this->assertSame(WebhookOutcome::InFlight, $store->claimSubscription($this->subscription(), 'tok-b'));
    }

    public function test_a_backward_state_cannot_re_dispatch_an_in_flight_delivery(): void
    {
        $store = app(TransactionStore::class);

        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('0'), 'tok-a'));
        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('1'), 'tok-b'));

        // Going back down the ladder is a replay. Allowing it lets two states ping-pong
        // the row and re-dispatch whatever is still in flight on it.
        $this->assertSame(WebhookOutcome::Stale, $store->applyWebhook($this->payload('0'), 'tok-c'));
    }

    /**
     * WLCM has been observed emitting states its own docs do not list. Admitting one
     * unconditionally let it walk a settled payment back off its terminal rung, and the
     * money state could then be dispatched all over again.
     */
    public function test_an_unmodelled_state_cannot_reopen_a_terminal_row(): void
    {
        $store = app(TransactionStore::class);
        $store->applyWebhook($this->payload('2'), 'tok-a');
        $store->markProcessed($this->payload('2'), 'tok-a');

        $this->assertSame(WebhookOutcome::Stale, $store->applyWebhook($this->payload('7'), 'tok-b'));
        $this->assertSame(WebhookOutcome::Duplicate, $store->applyWebhook($this->payload('2'), 'tok-c'));
    }

    public function test_a_replay_through_an_unmodelled_state_cannot_re_fire_the_money_event(): void
    {
        $succeeded = 0;
        Event::listen(PaymentSucceeded::class, function () use (&$succeeded): void {
            $succeeded++;
        });

        $this->postJson('/wlcm/webhook', $this->signed('2'))->assertOk();
        $this->postJson('/wlcm/webhook', $this->signed('7'))->assertOk();
        // The replay the freshness window explicitly permits.
        $this->postJson('/wlcm/webhook', $this->signed('2'))->assertOk();

        $this->assertSame(1, $succeeded, 'one payment dispatched PaymentSucceeded twice');
    }

    // The ladder must still not refuse states we do not model — only stop them being a
    // way back out of a settled row.
    public function test_an_unmodelled_state_still_advances_a_live_row(): void
    {
        $store = app(TransactionStore::class);

        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('0'), 'tok-a'));
        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('7'), 'tok-b'));
        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('2'), 'tok-c'));
    }

    public function test_a_hard_killed_claim_is_only_retaken_after_the_lease(): void
    {
        $store = app(TransactionStore::class);
        $store->applyWebhook($this->payload('2'), 'tok-a');
        $store->markProcessed($this->payload('2'), 'tok-a');

        // A worker that died outright: claimed, never released, never stamped.
        WlcmTransaction::sole()->update([
            'processed_at' => null, 'dispatch_token' => 'ghost', 'claimed_at' => now(),
        ]);

        $this->assertSame(WebhookOutcome::InFlight, $store->applyWebhook($this->payload('2'), 'tok-b'));

        WlcmTransaction::sole()->update(['claimed_at' => now()->subMinutes(5)]);

        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('2'), 'tok-c'));
    }

    /**
     * Sub-second precision is dropped on both the write and the WHERE binding
     * (Grammar::getDateFormat is 'Y-m-d H:i:s', and both columns are second-resolution),
     * so two workers milliseconds apart can land on opposite sides of a second boundary.
     * The loser then reads the winner's live claim as future-dated and steals it. Not an
     * engine quirk — the truncation happens in php, so every engine can do this.
     */
    public function test_a_claim_stamped_across_a_second_boundary_is_not_stolen(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');
        $store = app(TransactionStore::class);

        $store->applyWebhook($this->payload('2'), 'tok-a');
        // The winner stamped one tick later than the loser's own frozen $now.
        WlcmTransaction::sole()->update(['claimed_at' => now()->addSecond()]);

        $this->assertSame(WebhookOutcome::InFlight, $store->applyWebhook($this->payload('2'), 'tok-b'));
    }

    public function test_a_subscription_claim_across_a_second_boundary_is_not_stolen(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');
        $store = app(TransactionStore::class);

        $store->claimSubscription($this->subscription(), 'tok-a');
        WlcmTransaction::sole()->update(['claimed_at' => now()->addSecond()]);

        $this->assertSame(WebhookOutcome::InFlight, $store->claimSubscription($this->subscription(), 'tok-b'));
    }

    /**
     * Pins the horizon itself. Widening it past one lease would let a skewed host hold a
     * row longer and longer; narrowing it back toward $now re-opens the steal above.
     */
    public function test_the_future_clamp_sits_exactly_one_lease_out(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');
        config(['wlcm.webhook.lease_seconds' => 60]);
        $store = app(TransactionStore::class);
        $store->applyWebhook($this->payload('2'), 'tok-a');

        // Inside the horizon: still someone's live claim.
        WlcmTransaction::sole()->update(['claimed_at' => now()->addSeconds(60)]);
        $this->assertSame(WebhookOutcome::InFlight, $store->applyWebhook($this->payload('2'), 'tok-b'));

        // Past it: no clock is that wrong, so the row must be recoverable.
        WlcmTransaction::sole()->update(['claimed_at' => now()->addSeconds(61)]);
        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('2'), 'tok-c'));
    }

    /**
     * The mirror of the horizon test, on the expiry end. Truncation floors the stored
     * stamp, so `<=` would call a claim expired up to a second early and dispatch a
     * handler that is still running — which is why the predicate uses strict `<`. Only a
     * claim sitting exactly ON the cutoff tells the two apart, and nothing else in the
     * suite or the battery ever manufactures one.
     */
    public function test_the_expiry_clamp_gives_a_claim_its_full_lease(): void
    {
        Carbon::setTestNow('2026-07-26 12:00:00');
        config(['wlcm.webhook.lease_seconds' => 60]);
        $store = app(TransactionStore::class);
        $store->applyWebhook($this->payload('2'), 'tok-a');

        // Exactly one lease old: the lease has been reached, not exceeded. Still live.
        WlcmTransaction::sole()->update(['claimed_at' => now()->subSeconds(60)]);
        $this->assertSame(WebhookOutcome::InFlight, $store->applyWebhook($this->payload('2'), 'tok-b'));

        // Past it: recoverable.
        WlcmTransaction::sole()->update(['claimed_at' => now()->subSeconds(61)]);
        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('2'), 'tok-c'));
    }

    public function test_a_future_dated_claim_is_dead_not_maximally_fresh(): void
    {
        $store = app(TransactionStore::class);
        $store->applyWebhook($this->payload('2'), 'tok-a');

        // One host with a skewed clock would otherwise suppress this payment for hours.
        WlcmTransaction::sole()->update([
            'processed_at' => null, 'dispatch_token' => 'ghost', 'claimed_at' => now()->addHours(5),
        ]);

        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('2'), 'tok-b'));
    }

    #[DataProvider('badLeaseValues')]
    public function test_an_unreadable_lease_throws_on_the_first_delivery(mixed $lease): void
    {
        config(['wlcm.webhook.lease_seconds' => $lease]);

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('Missing configuration: wlcm.webhook.lease_seconds');

        app(TransactionStore::class)->applyWebhook($this->payload('2'), 'tok-a');
    }

    /** @return array<string, array{mixed}> */
    public static function badLeaseValues(): array
    {
        return ['word' => ['off'], 'null' => [null], 'zero' => [0], 'negative' => [-1]];
    }

    /**
     * The real proof available in-process: a listener re-posts the identical body, so the
     * second delivery provably lands while the first is still mid-dispatch. Event::fake
     * cannot be used — the dispatch has to actually run for the re-entry to happen.
     */
    public function test_a_delivery_arriving_mid_dispatch_does_not_dispatch_again(): void
    {
        $succeeded = 0;
        $reentered = false;

        Event::listen(PaymentSucceeded::class, function () use (&$succeeded): void {
            $succeeded++;
        });

        Event::listen(WebhookReceived::class, function () use (&$reentered): void {
            if ($reentered) {
                return; // One shot, or this recurses forever.
            }

            $reentered = true;
            $this->postJson('/wlcm/webhook', $this->signed('2'))->assertOk();
        });

        $this->postJson('/wlcm/webhook', $this->signed('2'))->assertOk();

        $this->assertTrue($reentered, 'the re-entrant delivery never ran, so nothing was proven');
        $this->assertSame(1, $succeeded, 'a delivery landing mid-dispatch dispatched a second time');
        $this->assertSame(1, WlcmLog::query()->where('result', 'inflight')->count());
    }

    public function test_a_throwing_handler_releases_its_claim_for_the_next_redelivery(): void
    {
        $succeeded = 0;
        Event::listen(PaymentSucceeded::class, function () use (&$succeeded): void {
            $succeeded++;
        });

        config(['wlcm.webhook.handler' => ThrowingWebhookHandler::class]);
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/wlcm/webhook', $this->signed('2'));
            $this->fail('expected the handler throw to surface');
        } catch (RuntimeException $e) {
            $this->assertSame('handler exploded', $e->getMessage());
        }

        $this->withExceptionHandling();

        $row = WlcmTransaction::sole();
        $this->assertNull($row->dispatch_token, 'a dead dispatch left its claim held');
        $this->assertNull($row->claimed_at);
        $this->assertNull($row->processed_at);

        // No lease wait: the very next redelivery re-runs the whole dispatch.
        config(['wlcm.webhook.handler' => null]);
        $this->postJson('/wlcm/webhook', $this->signed('2'))->assertOk();
        $this->assertSame(2, $succeeded);
    }

    /**
     * The 'ok' audit write sits between taking the claim and the first event(). LogStore
     * swallows and report()s, but README:185 documents that report() itself can throw
     * when the app's default log channel is down — and that throw must not strand the
     * claim it is standing next to.
     */
    public function test_a_throwing_audit_write_does_not_strand_the_claim(): void
    {
        app()->instance(LogStore::class, new ThrowingLogStore);
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/wlcm/webhook', $this->signed('2'));
            $this->fail('expected the audit throw to surface');
        } catch (RuntimeException $e) {
            $this->assertSame('log channel is down', $e->getMessage());
        }

        $this->withExceptionHandling();

        $row = WlcmTransaction::sole();
        $this->assertNull($row->dispatch_token, 'a throwing audit write left the claim held');
        $this->assertNull($row->claimed_at);
        $this->assertNull($row->processed_at);
    }

    public function test_an_empty_required_field_is_refused_before_any_row_exists(): void
    {
        $data = $this->signed('2');
        $data['payment_id'] = '';

        $this->postJson('/wlcm/webhook', $data)->assertStatus(422);
        $this->assertSame(0, WlcmTransaction::query()->count());
    }

    public function test_prune_still_refuses_a_claimed_row(): void
    {
        config(['wlcm.storage.prune_days' => 1, 'wlcm.storage.stale_days' => 1]);

        WlcmTransaction::query()->create([
            'connection' => 'main', 'api' => 'partner', 'payment_id' => '7788',
            'state' => 2, 'dispatch_token' => 'tok-a', 'claimed_at' => now()->subYear(),
            'processed_at' => null, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);

        $command = $this->artisan('model:prune', ['--model' => [WlcmTransaction::class]]);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0);
        $command->run();

        $this->assertSame(1, WlcmTransaction::query()->count(), 'a claimed dispatch was reaped');
    }

    public function test_storage_off_leaves_every_claim_fresh(): void
    {
        config(['wlcm.storage.transactions' => false]);
        $store = app(TransactionStore::class);

        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('2'), 'tok-a'));
        $this->assertSame(WebhookOutcome::Fresh, $store->applyWebhook($this->payload('2'), 'tok-b'));
        $this->assertSame(0, WlcmTransaction::query()->count());
    }
}

final class ThrowingWebhookHandler implements WlcmWebhookHandler
{
    public function handle(WebhookPayload $payload): void
    {
        throw new RuntimeException('handler exploded');
    }
}

final class ThrowingLogStore implements LogStore
{
    public function out(string $connection, string $api, string $method, string $url, ?array $requestBody, mixed $responseBody, ?int $status, int $durationMs, array $ids = [], string $result = 'ok'): void {}

    public function webhook(string $connection, array $payload, string $result, array $ids = []): void
    {
        throw new RuntimeException('log channel is down');
    }
}
