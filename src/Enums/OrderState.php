<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Enums;

enum OrderState: string
{
    // The only value observed against the live API. WLCM has not published the full
    // set, so every read goes through tryFrom and an unknown value yields null.
    case Created = 'created';
}
