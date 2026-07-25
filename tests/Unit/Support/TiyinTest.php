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

    public function test_to_sum_rejects_negative(): void
    {
        $this->expectException(InvalidAmountException::class);
        Tiyin::toSum(-100);
    }

    public function test_from_sum_string_digit_math(): void
    {
        $this->assertSame(500000, Tiyin::fromSumString('5000.00'));
        $this->assertSame(120050, Tiyin::fromSumString('1200.50'));
        $this->assertSame(1000, Tiyin::fromSumString('10'));
        $this->assertSame(1050, Tiyin::fromSumString('10.5'));
        $this->assertSame(-1050, Tiyin::fromSumString('-10.50'));
    }

    public function test_from_sum_string_rejects_malformed(): void
    {
        $malformed = [
            'abc', '', '1,200.00', '1e3', '10.505', '1 200', '12.34.56',
            "5000\n", "5000.5\n", "5000\r\n", "\n5000",
            '92233720368547758.08', '99999999999999999999.99',
        ];

        foreach ($malformed as $bad) {
            try {
                Tiyin::fromSumString($bad);
                $this->fail("Expected InvalidAmountException for '{$bad}'");
            } catch (InvalidAmountException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_from_sum_string_separates_magnitude_from_malformed(): void
    {
        $this->expectExceptionMessage("SUM amount exceeds supported magnitude: '99999999999999999.99'");
        Tiyin::fromSumString('99999999999999999.99');
    }

    public function test_from_sum_string_malformed_keeps_its_message(): void
    {
        $this->expectExceptionMessage("Malformed SUM amount string: 'abc'");
        Tiyin::fromSumString('abc');
    }

    public function test_from_sum_string_valid_forms(): void
    {
        $this->assertSame(500000, Tiyin::fromSumString('5000.00'));
        $this->assertSame(500050, Tiyin::fromSumString('5000.5'));
        $this->assertSame(500000, Tiyin::fromSumString('5000'));
        $this->assertSame(-12050, Tiyin::fromSumString('-120.50'));
        $this->assertSame(0, Tiyin::fromSumString('0'));
        $this->assertSame(999999999999999999, Tiyin::fromSumString('9999999999999999.99'));
    }
}
