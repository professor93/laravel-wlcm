<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Enums;

enum PaymentProvider: string
{
    case Payme = 'payme';
    case Click = 'click';
    case Paylov = 'paylov';
    case Uzum = 'uzum';
    case Card = 'card';

    // Values above are what we SEND. The catalogue answers with display casing
    // (Payme, Click, Uzum), so tryFrom misses every live row; parse through here.
    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtolower($name));
    }
}
