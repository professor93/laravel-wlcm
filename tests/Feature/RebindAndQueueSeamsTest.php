<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Tests\TestCase;

/**
 * Two seams the README described inaccurately. Neither is a bug to fix — both are real
 * consequences of how the package is wired — so what is pinned here is the behaviour,
 * so the documentation cannot drift away from it again.
 */
final class RebindAndQueueSeamsTest extends TestCase
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

    /**
     * D11. The README said flatly that a rebind after the first `Wlcm::` call is ignored.
     * That is true OUTBOUND — the manager memoises the client, which captured the store at
     * construction — and false INBOUND, because the controller is resolved per request and
     * constructor-injects whatever is bound then.
     *
     * A merchant who rebinds late therefore gets a SPLIT audit trail: outgoing calls in the
     * old sink, webhook deliveries in the new one, and the README told them to expect the
     * whole thing in the old one.
     */
    public function test_a_late_rebind_is_ignored_outbound_and_honoured_inbound(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'external_id' => 't-1', 'state' => 1,
            'checkout_url' => 'https://x.test/410', 'message' => null,
            'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
        ], 200)]);

        // First Wlcm:: call of the process: the client captures the store bound right now.
        Wlcm::partner();

        $late = new RecordingLogStore;
        app()->instance(LogStore::class, $late);

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, externalId: 't-1');

        // Both halves asserted positively, not just the absence: the outbound entry has to
        // be found in the ORIGINAL sink. Asserting only that the late store stayed empty
        // would also pass if the entry vanished entirely, and would not notice a change in
        // how either path resolves its store.
        $this->assertSame([], $late->entries, 'the outbound path picked up a late rebind');
        $this->assertSame(1, WlcmLog::query()->where('direction', 'out')->count(), 'the outbound entry went nowhere');

        $this->postJson('/wlcm/webhook', $this->signed())->assertOk();

        $this->assertSame(['webhook'], $late->entries, 'the webhook path ignored a late rebind');
        // ...and correspondingly absent from the original sink. This is the split trail.
        $this->assertSame(0, WlcmLog::query()->where('direction', 'webhook')->count(), 'the webhook entry also hit the old sink');
    }

    /**
     * D14. `processed_at` marks the end of the REQUEST, not the end of the merchant's work.
     * A `ShouldQueue` listener returns immediately, the claim is stamped processed, and if
     * that queue never drains the payment is recorded as handled while nothing handled it —
     * and every redelivery is answered `duplicate`, so WLCM's own retries cannot save it.
     *
     * Not fixable inside the package: once the job is on the queue the package has no way
     * to learn whether it ran. Pinned and documented instead.
     */
    public function test_processed_at_is_stamped_before_a_queued_listener_runs(): void
    {
        Queue::fake();
        Event::listen(PaymentSucceeded::class, QueuedPaymentListener::class);

        $this->postJson('/wlcm/webhook', $this->signed())->assertOk();

        $row = WlcmTransaction::sole();
        $this->assertNotNull($row->processed_at, 'the claim was not stamped');

        // The listener has not run and may never run: the work is still on the queue.
        Queue::assertPushed(CallQueuedListener::class);

        // And the consequence a merchant needs to know about — the redelivery that would
        // have rescued them is refused as already handled.
        $this->postJson('/wlcm/webhook', $this->signed())->assertOk();
        $this->assertSame(1, WlcmTransaction::query()->count());
        $this->assertNotNull(WlcmTransaction::sole()->processed_at);
    }

    /**
     * The half of the cached-config problem that belongs to the package.
     *
     * The reported symptom is that rotating a webhook secret in `.env` is inert under
     * `php artisan config:cache` — the new secret 403s while the old baked one still 200s,
     * so a rotation that looks done is not done. That staleness is Laravel's: a cached
     * config file freezes `env()`.
     *
     * What we own is that the package re-reads the secret from config on EVERY delivery,
     * so the rotation lands the moment the config does. Pinned here, because if this ever
     * became a captured-at-boot read the rotation would be inert even with no cache at
     * all, and the documentation would then be wrong in a much worse way.
     */
    public function test_the_webhook_secret_is_read_fresh_on_every_delivery(): void
    {
        $this->postJson('/wlcm/webhook', $this->signed())->assertOk();

        config(['wlcm.connections.main.partner.webhook_secret' => 'whsec_rotated']);

        // Old secret is refused the moment config changes — no reboot, no rebind.
        $this->postJson('/wlcm/webhook', $this->signed('7789'))->assertForbidden();
        $this->postJson('/wlcm/webhook', $this->signed('7789', 'whsec_rotated'))->assertOk();
    }

    /** @return array<string, string> */
    private function signed(string $paymentId = '7788', string $secret = 'whsec_test'): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => $paymentId,
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:{$paymentId}:2:2026-07-24 12:00:00", $secret);

        return $data;
    }
}

final class RecordingLogStore implements LogStore
{
    /** @var list<string> */
    public array $entries = [];

    /** @param array<string, mixed>|null $requestBody */
    public function out(string $connection, string $api, string $method, string $url, ?array $requestBody, mixed $responseBody, ?int $status, ?int $durationMs, array $ids = [], string $result = 'ok'): void
    {
        $this->entries[] = 'out';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $ids
     */
    public function webhook(string $connection, array $payload, string $result = 'ok', array $ids = []): void
    {
        $this->entries[] = 'webhook';
    }
}

final class QueuedPaymentListener implements ShouldQueue
{
    public function handle(PaymentSucceeded $event): void
    {
        //
    }
}
