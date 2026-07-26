<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

// Extends WlcmException on purpose: a bare RuntimeException escapes every merchant
// catch (WlcmException).
final class WebhookClaimException extends WlcmException
{
    public static function contended(string $key): self
    {
        return new self("wlcm webhook claim contended out: {$key}");
    }
}
