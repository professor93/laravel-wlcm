<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Enums;

enum PaymentState: int
{
    case Created = 0;
    case Initiating = 1;
    case Success = 2;
    case CancelledDuringInit = -1;
    case Cancelled = -2;
}
