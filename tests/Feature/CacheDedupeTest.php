<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Partner\Data\Order;
use Uzbek\Wlcm\Storage\CacheTransactionStore;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

final class CacheDedupeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('wlcm.storage.transactions', 'cache');
        // Array driver is per-process, which is enough to prove the logic; the class
        // docblock says not to use it in production for exactly that reason.
        $app['config']->set('cache.default', 'array');
    }

    /** @return array<string, mixed> */
    private function payload(string $state = '2', string $paymentId = '2'): array
    {
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', "1:{$paymentId}:{$state}:{$timestamp}", 'whsec_test');

        return [
            'order_id' => '1', 'payment_id' => $paymentId, 'amount' => 1000,
            'state' => $state, 'timestamp' => $timestamp, 'signature' => $signature,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function deliver(array $payload): TestResponse
    {
        return $this->postJson('/'.WebhookRoute::path(config('wlcm.webhook.path')), $payload);
    }

    public function test_the_cache_store_is_bound_when_configured(): void
    {
        $this->assertInstanceOf(CacheTransactionStore::class, app(TransactionStore::class));
    }

    public function test_the_row_store_is_bound_by_default(): void
    {
        config()->set('wlcm.storage.transactions', true);
        $this->app->forgetInstance(TransactionStore::class);

        $this->assertNotInstanceOf(CacheTransactionStore::class, app(TransactionStore::class));
    }

    public function test_a_first_delivery_dispatches(): void
    {
        Event::fake();

        $this->deliver($this->payload())->assertOk();

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_an_identical_replay_does_not_dispatch_again(): void
    {
        $payload = $this->payload();
        $this->deliver($payload)->assertOk();

        Event::fake();
        $this->deliver($payload)->assertOk();

        // The whole point: without any dedupe this fires the listener a second time.
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_a_different_payment_still_dispatches(): void
    {
        $this->deliver($this->payload(paymentId: '2'))->assertOk();

        Event::fake();
        $this->deliver($this->payload(paymentId: '999'))->assertOk();

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_a_different_state_on_the_same_payment_dispatches(): void
    {
        // Documented limitation: the key is per (payment_id, state) with no ordering,
        // so a state change is a new delivery. The SQL store would apply a ladder here.
        $this->deliver($this->payload(state: '1'))->assertOk();

        Event::fake();
        $this->deliver($this->payload(state: '2'))->assertOk();

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_no_audit_rows_are_written(): void
    {
        // There is no table; the audit methods are deliberate no-ops.
        $store = app(TransactionStore::class);

        $store->recordOrder('main', ['amount' => 1], new Order(1, null, 1, 'created'));

        $this->addToAssertionCount(1);
    }

    public function test_an_unknown_backend_name_throws(): void
    {
        config()->set('wlcm.storage.transactions', 'redis-ish');
        $this->app->forgetInstance(TransactionStore::class);

        $this->expectException(MissingConfigurationException::class);

        app(TransactionStore::class);
    }
}
