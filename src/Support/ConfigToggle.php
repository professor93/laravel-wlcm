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
            default => throw MissingConfigurationException::forKey($key, 'true, false, "1", "0", null, ""'),
        };
    }

    /**
     * The audit toggle answers "is logging on", never "am I the configured driver".
     *
     * All three drivers used to ask the second question, so changing `storage.logs` from
     * one driver to another in a live process sent every entry NOWHERE — the resolved
     * singleton disqualified itself and the newly named driver was never constructed. No
     * rows, no bytes, no error, no report(), delivery still 200. The driver is fixed at
     * first resolve by design; what was broken is that it stopped writing at all.
     *
     * Same accepted set as the provider's own resolve, so a value that boots cannot then
     * become unreadable at write time, and a typo still fails loudly rather than
     * silently discarding the trail.
     */
    public static function logsEnabled(mixed $value): bool
    {
        return match ($value) {
            'database', 'file', 'log', true, '1' => true,
            false, '0', null, '' => false,
            default => throw MissingConfigurationException::forKey('wlcm.storage.logs', '"database", "file", "log", true, "1" to log; false, "0", null, "" to disable'),
        };
    }
}
