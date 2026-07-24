<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Enums\PaymentState;

final class PaymentStateTest extends TestCase
{
    public function test_full_server_state_map(): void
    {
        $this->assertSame(PaymentState::Created, PaymentState::from(0));
        $this->assertSame(PaymentState::Initiating, PaymentState::from(1));
        $this->assertSame(PaymentState::Success, PaymentState::from(2));
        $this->assertSame(PaymentState::CancelledDuringInit, PaymentState::from(-1));
        $this->assertSame(PaymentState::Cancelled, PaymentState::from(-2));
        $this->assertCount(5, PaymentState::cases());
    }
}
