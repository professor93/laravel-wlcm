<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

final class MissingConfigurationException extends WlcmException
{
    /**
     * The recovery line is not decoration. A value this exception rejects, baked into
     * `bootstrap/cache/config.php`, takes the whole host app down AND its own recovery
     * tooling with it: every request 500s, `php artisan config:clear` exits 1 with this
     * same exception, `artisan list` fails, `artisan serve` will not start. Deleting the
     * cache file by hand is the only way back, and whoever is reading this is staring at
     * a dead app with no other clue.
     */
    private const string RECOVERY = ' If your config is cached, `php artisan config:clear` fails with this same error — delete bootstrap/cache/config.php by hand to recover.';

    public static function forKey(string $key, ?string $accepted = null): self
    {
        // Prefix kept verbatim: it is what merchants grep for and what the docs quote.
        return new self(
            "Missing configuration: {$key}"
            .($accepted === null ? '' : " (accepted: {$accepted})")
            .self::RECOVERY,
        );
    }
}
