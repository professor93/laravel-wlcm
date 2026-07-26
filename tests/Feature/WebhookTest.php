<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Uzbek\Wlcm\Events\PaymentCancelled;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Events\SubscriptionActivated;
use Uzbek\Wlcm\Events\SubscriptionCancelled;
use Uzbek\Wlcm\Events\SubscriptionCharged;
use Uzbek\Wlcm\Events\SubscriptionChargeFailed;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;
use Uzbek\Wlcm\Webhooks\WlcmSubscriptionWebhookHandler;
use Uzbek\Wlcm\Webhooks\WlcmWebhookHandler;

final class WebhookTest extends TestCase
{
    /** @return array<string, string> */
    private function payload(string $state = '2'): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '10.00', 'state' => $state, 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:{$state}:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    /**
     * `amount` rides OUTSIDE the signature base by WLCM's design, so one captured
     * delivery lets anyone set it freely. A negative reaches an `unsignedBigInteger`
     * column that mysql rejects with a 500 and postgres accepts — storing a negative
     * money value and firing the events. Same input, opposite outcomes, so it is refused
     * on both before any claim exists.
     */
    public function test_a_negative_amount_is_refused(): void
    {
        Event::fake();
        $data = $this->payload();
        $data['amount'] = '-100.00';

        $this->postJson('/wlcm/webhook', $data)->assertStatus(422);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_a_zero_amount_is_still_accepted(): void
    {
        Event::fake();
        $data = $this->payload();
        $data['amount'] = '0.00';

        $this->postJson('/wlcm/webhook', $data)->assertOk();
        Event::assertDispatched(PaymentSucceeded::class);
    }

    /**
     * Both signature bases are bare colon joins with no domain tag, so a payment base
     * `{order_id}:{payment_id}:{state}:{timestamp}` is byte-identical to a subscription
     * base `{external_order_id}:{event}:{timestamp}` when the reference carries the
     * colon. A captured payment delivery could therefore be replayed as a subscription
     * one with its signature UNCHANGED. Refusing an event that is not a `subscription.`
     * kind closes the payment->subscription direction without touching the wire format
     * or constraining merchant free text.
     */
    /**
     * There are TWO ways to split `order_id:payment_id:state` across the subscription
     * base, and only one of them yields a numeric event. Both must be refused, or the
     * guard closes the tidy half of the attack and leaves the other open.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function collisionSplits(): array
    {
        // Illustrative cases only — NOT exhaustive, and claiming it was is what hid the
        // field-aligned split for three reviews. Coverage lives in
        // test_no_split_of_a_payment_base_is_accepted_as_a_subscription, which generates
        // every split instead of listing the ones someone thought of.
        return [
            'numeric event' => ['1254:7788', '2', '2026-07-24 12:00:00'],
            'colon event' => ['1254', '7788:2', '2026-07-24 12:00:00'],
            'timestamp split' => ['1254:7788:2', '2026-07-24 12', '00:00'],
            'deeper timestamp split' => ['1254:7788:2', '2026-07-24 12:00', '00'],
        ];
    }

    #[DataProvider('collisionSplits')]
    public function test_a_payment_delivery_cannot_be_replayed_as_a_subscription(string $reference, string $event, string $timestamp): void
    {
        Event::fake();
        // Freshness off, so the guard is what is being measured. It is a supported
        // setting, and a guard that only holds because a second check happens to catch
        // the leftovers is not a guard.
        config(['wlcm.webhook.tolerance_seconds' => null]);
        $payment = $this->payload();

        // Sanity: this really is the same signed string, or the case proves nothing.
        $this->assertSame(
            "1254:7788:2:{$payment['timestamp']}",
            "{$reference}:{$event}:{$timestamp}",
            'the split does not reconstruct the payment base',
        );

        $this->postJson('/wlcm/webhook', [
            'event' => $event,
            'external_order_id' => $reference,
            'timestamp' => $timestamp,
            'signature' => $payment['signature'], // byte-identical, never recomputed
        ])->assertStatus(422);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    /**
     * The guard must not key on a naming convention. WLCM has already been seen emitting
     * states its own docs omit, so an event kind we have not observed has to keep working
     * — refusing one would lose a real delivery, which outranks the tidier rule.
     */
    public function test_an_unfamiliar_event_kind_is_still_accepted(): void
    {
        Event::fake();
        $data = [
            'event' => 'billing.retry_scheduled', 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        // WLCM's signature base stays a colon join — only our own delivery_key preimage
        // became length-prefixed.
        $data['signature'] = hash_hmac('sha256', 'order-1001:billing.retry_scheduled:1745740800000', 'whsec_test');

        $this->postJson('/wlcm/webhook', $data)->assertOk();
        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_a_real_subscription_event_still_passes(): void
    {
        Event::fake();
        $data = [
            'event' => 'subscription.charged', 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        $data['signature'] = hash_hmac('sha256', 'order-1001:subscription.charged:1745740800000', 'whsec_test');

        $this->postJson('/wlcm/webhook', $data)->assertOk();
        Event::assertDispatched(WebhookReceived::class);
    }

    /**
     * The reverse direction of the cross-family collision: a genuine SUBSCRIPTION
     * signature over `A:B:subscription.charged:ts` is byte-identical to a PAYMENT base
     * `{order_id}:{payment_id}:{state}:{timestamp}`, so it replays as a payment with its
     * signature untouched. The colon guard constrains the subscription family only, so it
     * never saw this.
     */
    public function test_a_subscription_delivery_cannot_be_replayed_as_a_payment(): void
    {
        Event::fake();
        config(['wlcm.webhook.tolerance_seconds' => null]);

        $signature = hash_hmac('sha256', 'A:B:subscription.charged:1745740800000', 'whsec_test');

        $this->postJson('/wlcm/webhook', [
            'order_id' => 'A', 'payment_id' => 'B', 'state' => 'subscription.charged',
            'amount' => '10.00', 'external_id' => 'ATTACKER',
            'timestamp' => '1745740800000', 'signature' => $signature,
        ])->assertStatus(422);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_a_colon_in_a_server_minted_payment_id_is_refused(): void
    {
        Event::fake();
        config(['wlcm.webhook.tolerance_seconds' => null]);
        $data = $this->payload();
        $data['payment_id'] = 'subscription.charged:2026-07-24 12';

        $this->postJson('/wlcm/webhook', $data)->assertStatus(422);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    /**
     * Generated, not hand-listed. Three hand-written completeness arguments about this
     * guard have now failed, each because someone enumerated the splits they thought of.
     * This walks EVERY way a captured payment base can be cut across the subscription
     * base's three fields and asserts each is refused — the field-aligned split
     * (X=order_id, E=payment_id, T'="state:timestamp") is the one hands kept missing.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function everyCrossFamilySplit(): array
    {
        $cases = [];

        foreach (['2026-07-24 12:00:00', '1745740800000'] as $stamp) {
            $base = "1254:7788:2:{$stamp}";
            $parts = explode(':', $base);
            $n = count($parts);

            for ($i = 1; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $x = implode(':', array_slice($parts, 0, $i));
                    $e = implode(':', array_slice($parts, $i, $j - $i));
                    $t = implode(':', array_slice($parts, $j));

                    if ("{$x}:{$e}:{$t}" !== $base) {
                        continue; // not a reconstruction, nothing to refuse
                    }

                    $cases["{$stamp} | {$x} | {$e}"] = [$x, $e, $t];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('everyCrossFamilySplit')]
    public function test_no_split_of_a_payment_base_is_accepted_as_a_subscription(string $reference, string $event, string $timestamp): void
    {
        Event::fake();
        // Freshness off: the guard must hold on its own, not because a later check
        // happens to catch the leftovers. Null tolerance is supported and documented.
        config(['wlcm.webhook.tolerance_seconds' => null]);

        // The split reconstructs the captured payment base, so that IS the signed string.
        $base = "{$reference}:{$event}:{$timestamp}";
        $this->assertStringStartsWith('1254:7788:2:', $base, 'the split does not reconstruct a payment base');
        $signature = hash_hmac('sha256', $base, 'whsec_test');

        $this->postJson('/wlcm/webhook', [
            'event' => $event, 'external_order_id' => $reference,
            'timestamp' => $timestamp, 'signature' => $signature,
        ])->assertStatus(422);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_success_webhook_fires_events(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->payload('2'))->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->paymentId === '7788');
        Event::assertNotDispatched(PaymentCancelled::class);
    }

    public function test_cancelled_webhook(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->payload('-2'))->assertOk();

        Event::assertDispatched(PaymentCancelled::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_invalid_signature_rejected(): void
    {
        $data = $this->payload();
        $data['signature'] = str_repeat('0', 64);

        $this->postJson('/wlcm/webhook', $data)->assertForbidden();
    }

    public function test_invalid_signature_fires_no_listener(): void
    {
        Event::fake([WebhookReceived::class, PaymentSucceeded::class, PaymentCancelled::class]);
        $data = $this->payload();
        $data['signature'] = str_repeat('0', 64);

        $this->postJson('/wlcm/webhook', $data)->assertForbidden();

        Event::assertNothingDispatched();
    }

    public function test_without_the_transactions_table_a_replay_fires_events_again(): void
    {
        Event::fake();
        $data = $this->payload('2');

        $this->postJson('/wlcm/webhook', $data)->assertOk();
        $this->postJson('/wlcm/webhook', $data)->assertOk();

        Event::assertDispatchedTimes(PaymentSucceeded::class, 2);
        Event::assertDispatchedTimes(WebhookReceived::class, 2);
    }

    public function test_a_delivery_without_a_usable_state_never_reaches_the_dto(): void
    {
        $missing = $this->payload();
        unset($missing['state']);

        $this->postJson('/wlcm/webhook', $missing)->assertStatus(422);

        $empty = $this->payload();
        $empty['state'] = '';
        $empty['signature'] = hash_hmac('sha256', '1254:7788::2026-07-24 12:00:00', 'whsec_test');

        $this->postJson('/wlcm/webhook', $empty)
            ->assertStatus(422)
            ->assertJson(['message' => 'Missing field: state']);
    }

    public function test_array_typed_optional_field_is_refused_not_fatal(): void
    {
        $data = $this->payload();
        $data['external_id'] = ['ORD-1'];
        $data['provider'] = ['payme'];
        $data['signature'] = str_repeat('0', 64);

        $this->postJson('/wlcm/webhook', $data)->assertForbidden();
    }

    public function test_signed_payment_delivery_with_every_optional_field_poisoned_still_dispatches(): void
    {
        Event::fake();

        $data = $this->payload('2');
        $data['external_id'] = ['ORD-1'];
        $data['provider'] = ['payme'];

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->externalId === ''
            && $e->payload->provider === ''
            && $e->payload->paymentId === '7788'
            && $e->payload->amountTiyin() === 1000);
    }

    public function test_config_handler_invoked(): void
    {
        $handler = new class implements WlcmWebhookHandler
        {
            public ?WebhookPayload $received = null;

            public function handle(WebhookPayload $payload): void
            {
                $this->received = $payload;
            }
        };
        $this->app->instance($handler::class, $handler);
        config()->set('wlcm.webhook.handler', $handler::class);

        $this->postJson('/wlcm/webhook', $this->payload())->assertOk();

        $this->assertNotNull($handler->received);
        $this->assertSame('7788', $handler->received->paymentId);
    }

    public function test_route_registered(): void
    {
        $this->assertTrue(app('router')->has('wlcm.webhook'));
    }

    public function test_unknown_connection_is_not_routed(): void
    {
        $this->postJson('/wlcm/webhook/nope', $this->payload())->assertNotFound();
    }

    public function test_known_connection_segment_still_verifies(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook/main', $this->payload('2'))->assertOk();

        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->connection === 'main');
    }

    public function test_handler_not_implementing_contract_throws(): void
    {
        config()->set('wlcm.webhook.handler', \stdClass::class);

        $this->withoutExceptionHandling();
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.webhook.handler');

        $this->postJson('/wlcm/webhook', $this->payload());
    }

    public function test_typod_handler_class_dies_in_the_container_not_at_boot(): void
    {
        config()->set('wlcm.webhook.handler', 'App\\Wlcm\\NoSuchHandler');

        $this->assertTrue(app('router')->has('wlcm.webhook'));

        $this->withoutExceptionHandling();
        $this->expectException(BindingResolutionException::class);

        $this->postJson('/wlcm/webhook', $this->payload());
    }

    public function test_a_connection_added_after_boot_does_not_widen_the_route(): void
    {
        config()->set('wlcm.connections.late', [
            'partner' => ['base_url' => 'https://late.test/api/v1', 'api_key' => 'k', 'api_secret' => 's', 'webhook_secret' => 'whsec_test'],
        ]);

        $this->postJson('/wlcm/webhook/late', $this->payload())->assertNotFound();
    }

    public function test_int_typed_payment_payload_end_to_end(): void
    {
        Event::fake();

        $data = [
            'external_id' => 'ORD-1', 'order_id' => 114, 'payment_id' => 98,
            'amount' => '100000.00', 'state' => 2, 'provider' => 'payme',
            'timestamp' => 1748765432000,
        ];
        $data['signature'] = hash_hmac('sha256', '114:98:2:1748765432000', 'whsec_test');

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->paymentId === '98'
            && $e->payload->amountTiyin() === 10000000);
    }

    /** @return array<string, int|string> */
    private function subscriptionPayload(string $event = 'subscription.activated'): array
    {
        $data = [
            'event' => $event, 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        $data['signature'] = hash_hmac('sha256', "order-1001:{$event}:1745740800000", 'whsec_test');

        return $data;
    }

    public function test_subscription_activated_event(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload())->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(SubscriptionActivated::class, fn (SubscriptionActivated $e) => $e->payload->subscriptionId === 8);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentCancelled::class);
    }

    public function test_subscription_charged_and_failed_and_cancelled_events(): void
    {
        Event::fake();

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload('subscription.charged'))->assertOk();
        $this->postJson('/wlcm/webhook', $this->subscriptionPayload('subscription.charge_failed'))->assertOk();
        $this->postJson('/wlcm/webhook', $this->subscriptionPayload('subscription.cancelled'))->assertOk();

        Event::assertDispatched(SubscriptionCharged::class);
        Event::assertDispatched(SubscriptionChargeFailed::class);
        Event::assertDispatched(SubscriptionCancelled::class);
        Event::assertNotDispatched(SubscriptionActivated::class);
    }

    public function test_payment_handler_not_invoked_for_subscription_payload(): void
    {
        $handler = new class implements WlcmWebhookHandler
        {
            public ?WebhookPayload $received = null;

            public function handle(WebhookPayload $payload): void
            {
                $this->received = $payload;
            }
        };
        $this->app->instance($handler::class, $handler);
        config()->set('wlcm.webhook.handler', $handler::class);

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload())->assertOk();

        $this->assertNull($handler->received);
    }

    public function test_subscription_handler_invoked(): void
    {
        $handler = new class implements WlcmSubscriptionWebhookHandler
        {
            public ?SubscriptionWebhookPayload $received = null;

            public function handle(SubscriptionWebhookPayload $payload): void
            {
                $this->received = $payload;
            }
        };
        $this->app->instance($handler::class, $handler);
        config()->set('wlcm.webhook.subscription_handler', $handler::class);

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload())->assertOk();

        $this->assertNotNull($handler->received);
        $this->assertSame('subscription.activated', $handler->received->event);
    }

    public function test_subscription_handler_not_implementing_contract_throws(): void
    {
        config()->set('wlcm.webhook.subscription_handler', \stdClass::class);

        $this->withoutExceptionHandling();
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.webhook.subscription_handler');

        $this->postJson('/wlcm/webhook', $this->subscriptionPayload());
    }

    public function test_real_captured_delivery_end_to_end(): void
    {
        // Verbatim tunnel capture: body-only signature, no webhook headers.
        config()->set('wlcm.connections.main.partner.webhook_secret', 'whsec_probe_2026');
        Event::fake();

        $this->postJson('/wlcm/webhook', [
            'external_id' => 'probe-wh2-170340', 'order_id' => 414, 'payment_id' => 172,
            'amount' => '3000.00', 'state' => 2, 'provider' => 'mock_payme',
            'timestamp' => 1784912687183,
            'signature' => 'c9ce779c9bab0543a1c9d1fa9d4ca5a3c2c4d3ee28b85d4d37aa384b6abd77c7',
        ])->assertOk();

        Event::assertDispatched(PaymentSucceeded::class, fn (PaymentSucceeded $e) => $e->payload->paymentId === '172'
            && $e->payload->amountTiyin() === 300000);
    }

    public function test_subscription_array_typed_optional_field_is_refused_not_fatal(): void
    {
        $data = $this->subscriptionPayload();
        $data['external_user_id'] = ['user-1001'];
        $data['subscription_id'] = ['8'];
        $data['amount'] = ['49900.00'];
        $data['status'] = ['active'];
        $data['signature'] = str_repeat('0', 64);

        $this->postJson('/wlcm/webhook', $data)->assertForbidden();
    }

    public function test_signed_subscription_delivery_with_every_optional_field_poisoned_still_dispatches(): void
    {
        Event::fake();

        $data = $this->subscriptionPayload();
        $data['external_user_id'] = ['user-1001'];
        $data['subscription_id'] = ['8'];
        $data['amount'] = ['49900.00'];
        $data['status'] = ['active'];

        $this->postJson('/wlcm/webhook', $data)->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(SubscriptionActivated::class, fn (SubscriptionActivated $e) => $e->payload->externalUserId === ''
            && $e->payload->subscriptionId === 0
            && $e->payload->amount === '0'
            && $e->payload->status === ''
            && $e->payload->externalOrderId === 'order-1001');
    }

    public function test_subscription_invalid_signature_rejected(): void
    {
        $data = $this->subscriptionPayload();
        $data['signature'] = str_repeat('0', 64);

        $this->postJson('/wlcm/webhook', $data)->assertForbidden();
    }
}
