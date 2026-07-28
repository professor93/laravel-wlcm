<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Uzbek\Wlcm\Enums\OrderState;
use Uzbek\Wlcm\Partner\Data\Order;
use Uzbek\Wlcm\Tests\TestCase;

final class OrderDataTest extends TestCase
{
    public function test_it_hydrates_the_live_response_shape(): void
    {
        // Live sandbox response, verbatim (order 642, probed 2026-07-27).
        $order = Order::fromArray([
            'order_id' => 642,
            'external_id' => 'probe-20260727-092137-69d421',
            'amount' => 100000,
            'status' => 'created',
        ]);

        $this->assertSame(642, $order->orderId);
        $this->assertSame('probe-20260727-092137-69d421', $order->externalId);
        $this->assertSame(100000, $order->amountTiyin);
        $this->assertSame('created', $order->status);
        $this->assertSame(OrderState::Created, $order->statusEnum());
    }

    public function test_an_absent_external_id_is_null_not_empty_string(): void
    {
        // The API accepts an order with no external_id and echoes null (order 644).
        $order = Order::fromArray([
            'order_id' => 644,
            'external_id' => null,
            'amount' => 100000,
            'status' => 'created',
        ]);

        $this->assertNull($order->externalId);
    }

    public function test_it_survives_a_response_missing_every_optional_key(): void
    {
        $order = Order::fromArray(['order_id' => 7]);

        $this->assertSame(7, $order->orderId);
        $this->assertNull($order->externalId);
        $this->assertNull($order->amountTiyin);
        $this->assertNull($order->status);
        $this->assertNull($order->statusEnum());
    }

    public function test_a_numeric_string_amount_is_coerced(): void
    {
        // The live API sends a JSON int, but a stringified amount must not become null.
        $order = Order::fromArray(['order_id' => 7, 'amount' => '250000']);

        $this->assertSame(250000, $order->amountTiyin);
    }

    public function test_an_unobserved_status_yields_null_rather_than_throwing(): void
    {
        // Only 'created' has ever been seen. The full vocabulary is unknown, so an
        // unknown value must never be able to take down a caller.
        $order = Order::fromArray(['order_id' => 7, 'status' => 'awaiting_provider']);

        $this->assertSame('awaiting_provider', $order->status);
        $this->assertNull($order->statusEnum());
    }

    public function test_a_non_numeric_order_id_falls_back_to_zero(): void
    {
        $order = Order::fromArray(['order_id' => ['nested']]);

        $this->assertSame(0, $order->orderId);
    }
}
