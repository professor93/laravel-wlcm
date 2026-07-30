<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Uzbek\Wlcm\Partner\Data\OrderLink;
use Uzbek\Wlcm\Tests\TestCase;

final class OrderLinkDataTest extends TestCase
{
    public function test_it_hydrates_the_live_response_shape(): void
    {
        // Live sandbox response, verbatim (order 661, probed 2026-07-29).
        $link = OrderLink::fromArray([
            'order_id' => 661,
            'external_id' => 'probe3-20260729-205040-589f3f',
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/661',
        ]);

        $this->assertSame(661, $link->orderId);
        $this->assertSame('probe3-20260729-205040-589f3f', $link->externalId);
        $this->assertSame('https://sandbox.wlcm.uz/pay/661', $link->checkoutUrl);
    }

    public function test_an_order_linked_without_an_external_id_yields_null(): void
    {
        $link = OrderLink::fromArray([
            'order_id' => 644,
            'external_id' => null,
            'checkout_url' => 'https://sandbox.wlcm.uz/pay/644',
        ]);

        $this->assertNull($link->externalId);
    }

    public function test_it_survives_a_response_missing_every_optional_key(): void
    {
        $link = OrderLink::fromArray(['order_id' => 7]);

        $this->assertSame(7, $link->orderId);
        $this->assertNull($link->externalId);
        $this->assertNull($link->checkoutUrl);
    }

    public function test_a_non_numeric_order_id_falls_back_to_zero(): void
    {
        $link = OrderLink::fromArray(['order_id' => ['nested']]);

        $this->assertSame(0, $link->orderId);
    }
}
