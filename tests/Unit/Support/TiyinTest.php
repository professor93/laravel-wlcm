<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Support\Tiyin;

final class TiyinTest extends TestCase
{
    public function test_converts_whole_sums(): void
    {
        $this->assertSame(1000, Tiyin::toSum(100000));
        $this->assertSame(1, Tiyin::toSum(100));
    }

    public function test_rejects_fractional_sum(): void
    {
        $this->expectException(InvalidAmountException::class);
        Tiyin::toSum(150);
    }

    public function test_rejects_zero_and_negative(): void
    {
        $this->expectException(InvalidAmountException::class);
        Tiyin::toSum(0);
    }

    public function test_from_sum_string_digit_math(): void
    {
        $this->assertSame(500000, Tiyin::fromSumString('5000.00'));
        $this->assertSame(120050, Tiyin::fromSumString('1200.50'));
        $this->assertSame(1000, Tiyin::fromSumString('10'));
        $this->assertSame(1050, Tiyin::fromSumString('10.5'));
        $this->assertSame(-1050, Tiyin::fromSumString('-10.50'));
    }
}
