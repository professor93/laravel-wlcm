<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

enum WebhookOutcome
{
    case Fresh;
    case Duplicate;
    // Another worker holds a live claim on this exact delivery. Not "already done".
    case InFlight;
    case Stale;
}
