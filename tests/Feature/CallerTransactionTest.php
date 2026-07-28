<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Events\SubscriptionCharged;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\CallerTransactionException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Tests\TestCase;

/**
 * The refusal itself, on all three paths that write a transaction row.
 *
 * RefreshDatabase holds a transaction open for the whole test, which is exactly the
 * shape being guarded — so these tests turn the opt-out OFF, against the base TestCase
 * which turns it on for every other suite. Read tests/TestCase.php for why the rest of
 * the suite runs opted in and what covers the shipped default instead.
 */
final class CallerTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('wlcm.storage.transactions', true);
        $app['config']->set('wlcm.allow_caller_transaction', false);
    }

    /** @return array<string, string> */
    private function signedPayment(): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', '1254:7788:2:2026-07-24 12:00:00', 'whsec_test');

        return $data;
    }

    /** @return array<string, string|int> */
    private function signedSubscription(): array
    {
        $data = [
            'event' => 'subscription.charged', 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        $data['signature'] = hash_hmac('sha256', 'order-1001:subscription.charged:1745740800000', 'whsec_test');

        return $data;
    }

    public function test_a_payment_delivery_is_refused_inside_a_caller_transaction(): void
    {
        Event::fake();
        $this->withoutExceptionHandling();

        $this->expectException(CallerTransactionException::class);
        $this->expectExceptionMessage('wlcm.allow_caller_transaction');

        try {
            $this->postJson('/wlcm/webhook', $this->signedPayment());
        } finally {
            // The claim is the thing that would have rolled back. Nothing may be taken,
            // and above all no money event may fire — an event cannot be rolled back, so
            // one fired here plus a rollback is the duplicate this guard exists to stop.
            $this->assertSame(0, WlcmTransaction::query()->count());
            Event::assertNotDispatched(WebhookReceived::class);
            Event::assertNotDispatched(PaymentSucceeded::class);
        }
    }

    public function test_a_subscription_delivery_is_refused_inside_a_caller_transaction(): void
    {
        Event::fake();
        $this->withoutExceptionHandling();

        $this->expectException(CallerTransactionException::class);

        try {
            $this->postJson('/wlcm/webhook', $this->signedSubscription());
        } finally {
            $this->assertSame(0, WlcmTransaction::query()->count());
            Event::assertNotDispatched(SubscriptionCharged::class);
        }
    }

    /**
     * The load-bearing assertion of the whole fix. recordCheckout runs AFTER the POST, so
     * a guard placed where the row is written would throw only once WLCM already holds a
     * real payment — turning "no record of a payment that exists" into "no record, and we
     * threw at the caller too". Nothing may leave the process.
     */
    public function test_a_checkout_is_refused_before_anything_is_sent(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'external_id' => 't-1', 'state' => 1,
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/410', 'message' => null,
            'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
        ], 200)]);

        $this->expectException(CallerTransactionException::class);
        $this->expectExceptionMessage('Nothing was sent');

        try {
            Wlcm::partner()->checkout()->create(amountTiyin: 120000, externalId: 't-1');
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, WlcmTransaction::query()->count());
        }
    }

    // Same rule on the order path: recordOrder also runs after the POST, so the refusal
    // has to happen before anything leaves the process.
    public function test_an_order_is_refused_before_anything_is_sent(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 642, 'external_id' => 't-1', 'amount' => 100000, 'status' => 'created',
        ], 200)]);

        $this->expectException(CallerTransactionException::class);
        $this->expectExceptionMessage('Nothing was sent');

        try {
            Wlcm::partner()->orders()->create(amountTiyin: 100000, externalId: 't-1');
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, WlcmTransaction::query()->count());
        }
    }

    public function test_the_opt_out_restores_every_path(): void
    {
        config()->set('wlcm.allow_caller_transaction', true);

        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'external_id' => 't-1', 'state' => 1,
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/410', 'message' => null,
            'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
        ], 200)]);

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, externalId: 't-1');
        $this->postJson('/wlcm/webhook', $this->signedPayment())->assertOk();
        $this->postJson('/wlcm/webhook', $this->signedSubscription())->assertOk();

        // Three rows: the checkout (order 410), the payment delivery (order 1254, so it
        // adopts nothing), and the subscription delivery.
        Http::assertSentCount(1);
        $this->assertSame(3, WlcmTransaction::query()->count());
    }

    // No transaction row to lose means nothing to guard. Refusing here would cost a
    // merchant their payments for a hazard that cannot occur on a default install, and
    // would train them to set the opt-out for the wrong reason — which then silently
    // covers the real hazard the day they turn the store on.
    public function test_the_guard_is_silent_when_no_transaction_rows_are_kept(): void
    {
        config()->set('wlcm.storage.transactions', false);

        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'external_id' => 't-1', 'state' => 1,
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/410', 'message' => null,
            'transaction_id' => null, 'otp_sent_phone' => null, 'cid' => null,
        ], 200)]);

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, externalId: 't-1');
        $this->postJson('/wlcm/webhook', $this->signedPayment())->assertOk();

        Http::assertSentCount(1);
    }
}
