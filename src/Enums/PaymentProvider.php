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
}
