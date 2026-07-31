<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Webhooks;

use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\DeliveryHealth;

final class DeliveryHealthTest extends TestCase
{
    /**
     * A real failed delivery, verbatim from the live events endpoint (2026-08-01).
     *
     * @return array<string, mixed>
     */
    private function liveFailure(): array
    {
        return [
            'id' => 231, 'webhook_id' => 38, 'event_type' => 'payment.success',
            'status' => 'failed', 'attempts' => 1, 'response_code' => 503,
            'response_body' => '<h1>no tunnel here :(</h1>',
            'error_message' => 'HTTP 503: <h1>no tunnel here :(</h1>',
            'next_retry_at' => null, 'created_at' => '2026-07-30T13:46:40.830425Z',
        ];
    }

    public function test_a_real_failed_delivery_is_unhealthy(): void
    {
        $this->assertTrue(DeliveryHealth::problematic($this->liveFailure()));
    }

    public function test_a_transport_failure_with_no_response_code_is_unhealthy(): void
    {
        // The other live shape: no HTTP happened at all, so response_code is null.
        $delivery = $this->liveFailure();
        $delivery['response_code'] = null;
        $delivery['error_message'] = "Request error: Request URL is missing an 'http://' or 'https://' protocol.";

        $this->assertTrue(DeliveryHealth::problematic($delivery));
    }

    public function test_a_clean_delivery_is_healthy(): void
    {
        $this->assertFalse(DeliveryHealth::problematic([
            'status' => 'delivered', 'attempts' => 1, 'response_code' => 200,
            'error_message' => null, 'next_retry_at' => null,
        ]));
    }

    public function test_an_error_message_alone_makes_it_unhealthy(): void
    {
        // The load-bearing signal. "delivered" is a GUESS — no successful delivery has
        // ever been observed on this API — so the predicate must not rest on the status
        // string alone. A non-null error_message is a failure whatever the vocabulary.
        $this->assertTrue(DeliveryHealth::problematic([
            'status' => 'whatever_success_is_actually_called',
            'attempts' => 1, 'response_code' => 200,
            'error_message' => 'something went wrong',
        ]));
    }

    public function test_a_non_2xx_response_code_makes_it_unhealthy(): void
    {
        $this->assertTrue(DeliveryHealth::problematic([
            'status' => 'delivered', 'attempts' => 1, 'response_code' => 500, 'error_message' => null,
        ]));
    }

    public function test_repeated_attempts_make_it_unhealthy(): void
    {
        $this->assertTrue(DeliveryHealth::problematic([
            'status' => 'delivered', 'attempts' => 3, 'response_code' => 200, 'error_message' => null,
        ]));
    }

    public function test_a_past_retry_time_makes_it_unhealthy(): void
    {
        // A retry that was due and did not happen is a stuck delivery.
        $this->assertTrue(DeliveryHealth::problematic([
            'status' => 'pending', 'attempts' => 1, 'response_code' => null,
            'error_message' => null, 'next_retry_at' => '2020-01-01T00:00:00Z',
        ]));
    }

    public function test_a_future_retry_time_is_not_yet_a_problem(): void
    {
        $this->assertFalse(DeliveryHealth::problematic([
            'status' => 'delivered', 'attempts' => 1, 'response_code' => 200,
            'error_message' => null, 'next_retry_at' => '2099-01-01T00:00:00Z',
        ]));
    }

    public function test_the_rules_are_configurable(): void
    {
        config()->set('wlcm.webhook.alerts.response_code_not_in', [200, 418]);

        $this->assertFalse(DeliveryHealth::problematic([
            'status' => 'delivered', 'attempts' => 1, 'response_code' => 418, 'error_message' => null,
        ]));
    }

    public function test_an_unrecognised_delivery_shape_is_treated_as_unhealthy(): void
    {
        // A shape we cannot classify must not be reported as fine — silence about a
        // delivery is exactly what this predicate exists to prevent.
        $this->assertTrue(DeliveryHealth::problematic([]));
    }
}
