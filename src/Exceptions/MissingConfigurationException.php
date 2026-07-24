<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

final class MissingConfigurationException extends WlcmException
{
    public static function forKey(string $key): self
    {
        return new self("Missing configuration: {$key}");
    }
}
