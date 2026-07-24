<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Support;

use Uzbek\Wlcm\Exceptions\InvalidAmountException;

final class Tiyin
{
    // Some WLCM endpoints want SUM (subscriptions, fixed splits); package speaks tiyin only.
    public static function toSum(int $tiyin): int
    {
        if ($tiyin <= 0 || $tiyin % 100 !== 0) {
            throw new InvalidAmountException("Amount must be positive tiyin divisible by 100, got {$tiyin}");
        }

        return intdiv($tiyin, 100);
    }

    // Inbound SUM decimal strings ("5000.00") to int tiyin; digit math, no floats.
    public static function fromSumString(string $sum): int
    {
        [$whole, $fraction] = array_pad(explode('.', $sum, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        return (int) ($whole.$fraction);
    }
}
