<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Support;

use Uzbek\Wlcm\Exceptions\MissingConfigurationException;

final class ConfigToggle
{
    // (bool) reads 'off'/'no'/'disabled' as TRUE, so a typo widens instead of
    // narrowing. Documented forms only; anything else is a config error.
    public static function enabled(mixed $value, string $key): bool
    {
        return match ($value) {
            true, '1', 1 => true,
            false, '0', 0, null, '' => false,
            default => throw MissingConfigurationException::forKey($key),
        };
    }
}
