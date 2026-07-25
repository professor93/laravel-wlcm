<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class FakeTest extends TestCase
{
    public function test_fake_checkout_returns_default_response(): void
    {
        Wlcm::fake();

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test');

        $this->assertSame('https://checkout.fake/wlcm', $r->checkoutUrl);
    }

    public function test_fake_records_and_asserts(): void
    {
        $fake = Wlcm::fake();

        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-7');

        $fake->assertCheckoutCreated(fn (array $payload): bool => $payload['external_id'] === 'ORD-7'
            && $payload['amount'] === 120000);
    }

    public function test_fake_custom_response(): void
    {
        Wlcm::fake([
            '*/integrations/checkout' => ['order_id' => 42, 'state' => 0, 'checkout_url' => 'https://custom.test'],
        ]);

        $r = Wlcm::partner()->checkout()->create(amountTiyin: 100, returnUrl: 'https://x.test');

        $this->assertSame(42, $r->orderId);
        $this->assertSame('https://custom.test', $r->checkoutUrl);
    }

    public function test_assert_nothing_sent(): void
    {
        $fake = Wlcm::fake();

        $fake->assertNothingSent();
    }
}
