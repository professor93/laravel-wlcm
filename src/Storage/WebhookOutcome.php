<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

enum WebhookOutcome
{
    case Fresh;
    case Duplicate;
    case Stale;
}
