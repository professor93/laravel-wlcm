<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Uzbek\Wlcm\Partner\Data\OrderStatus;
use Uzbek\Wlcm\Tests\TestCase;

final class ReconcileTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function orderStatus(array $overrides = []): OrderStatus
    {
        return OrderStatus::fromArray(array_merge([
            'order_id' => 1, 'amount' => 1000, 'status' => 'pending', 'state' => 1,
            'is_paid' => false, 'is_pending' => true, 'is_cancelled' => false, 'is_completed' => false,
        ], $overrides));
    }

    public function test_an_in_flight_order_wants_polling(): void
    {
        $this->assertTrue($this->orderStatus(['state' => 1])->needsPolling());
        $this->assertTrue($this->orderStatus(['state' => 0])->needsPolling());
        $this->assertFalse($this->orderStatus(['state' => 1])->isSettled());
    }

    public function test_a_paid_order_is_settled_and_wants_nothing(): void
    {
        // The live PAID body drops top-level state entirely, so isPaid has to be enough.
        $paid = OrderStatus::fromArray([
            'order_id' => 1, 'amount' => 1000, 'status' => 'paid', 'is_paid' => true,
            'is_pending' => false, 'is_cancelled' => false, 'is_completed' => false,
            'status_explanation' => ['status' => 'paid', 'state' => '2', 'description' => 'ok'],
        ]);

        $this->assertTrue($paid->isSettled());
        $this->assertFalse($paid->needsPolling());
    }

    public function test_a_cancelled_order_is_settled(): void
    {
        $this->assertTrue($this->orderStatus(['state' => -2, 'is_cancelled' => true])->isSettled());
        $this->assertFalse($this->orderStatus(['state' => -2, 'is_cancelled' => true])->needsPolling());
    }

    public function test_a_terminal_state_number_settles_even_without_the_flags(): void
    {
        $this->assertTrue($this->orderStatus(['state' => -1])->isSettled());
        $this->assertFalse($this->orderStatus(['state' => -1])->needsPolling());
    }

    public function test_disabled_reports_nothing_to_poll(): void
    {
        config()->set('wlcm.reconcile.enabled', false);

        // Silently false rather than throwing, so wrapping code keeps working when the
        // feature is switched off in a hurry.
        $this->assertFalse($this->orderStatus(['state' => 1])->needsPolling());
    }

    public function test_the_state_lists_are_configurable(): void
    {
        config()->set('wlcm.reconcile.poll_states', [1]);

        $this->assertTrue($this->orderStatus(['state' => 1])->needsPolling());
        $this->assertFalse($this->orderStatus(['state' => 0])->needsPolling());
    }

    public function test_an_unmodelled_state_is_not_polled_forever(): void
    {
        // 7 is in neither list. Polling on a value nobody modelled is a loop with no
        // exit, so it answers false and isSettled() is the question to ask instead.
        $this->assertFalse($this->orderStatus(['state' => 7])->needsPolling());
        $this->assertFalse($this->orderStatus(['state' => 7])->isSettled());
    }

    public function test_a_missing_state_is_not_polled(): void
    {
        $this->assertFalse($this->orderStatus(['state' => null])->needsPolling());
    }
}
