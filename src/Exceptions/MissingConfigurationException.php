<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

final class MissingConfigurationException extends WlcmException
{
    /**
     * Keys read while the package BOOTS. Only these can end up baked into
     * `bootstrap/cache/config.php` in a state that takes the whole host app down — every
     * request 500s, and `php artisan config:clear`, `list` and `serve` all fail with this
     * same exception, so the tool you would reach for is broken too.
     *
     * The list exists because the recovery runbook used to be appended to EVERY message,
     * including keys thrown lazily at call time — an absent `api_secret`, an unknown
     * connection name, missing route middleware. `config:clear` works perfectly well for
     * those, so the advice was false, and printing it everywhere diluted the one message
     * that genuinely needs it.
     */
    private const array BOOT_READ_KEYS = ['wlcm.webhook.enabled'];

    private const string RECOVERY = ' This key is read while the package boots, so a bad value in a CACHED config takes the app down and breaks `php artisan config:clear` with this same error — delete bootstrap/cache/config.php by hand to recover. Note that `config:cache` REFUSES to bake a bad value here, because it boots the package; the wlcm.storage.* toggles cache silently and fail on first use instead.';

    public static function forKey(string $key, ?string $accepted = null, mixed $value = null): self
    {
        // Prefix kept verbatim: it is what merchants grep for and what the docs quote.
        return new self(
            "Missing configuration: {$key}"
            .($value === null ? '' : ' — '.self::render($value))
            .($accepted === null ? '' : " (accepted: {$accepted})")
            .(in_array($key, self::BOOT_READ_KEYS, true) ? self::RECOVERY : ''),
        );
    }

    /**
     * The offending value, rendered so printing it cannot make things worse. Exception
     * messages reach log aggregators verbatim, so a newline in a merchant's config value
     * would split one record into two and a control byte could corrupt the line — hence
     * the escaping and the length cap rather than plain interpolation.
     *
     * Null never reaches this method, but NOT for the reason first written here. The stated
     * reason — "null is a documented OFF value for every toggle, so nothing rejects it" —
     * is false: `leaseSeconds()` rejects null explicitly, since that key has no null
     * disable. What actually holds is the ternary in forKey(): null is the "no value
     * supplied" sentinel, so it is filtered before this method is called.
     *
     * The cost of using null as that sentinel, stated rather than hidden: a null
     * `lease_seconds` produces a message with no value shown. The accepted-values text
     * carries the information instead ("there is no null disable").
     */
    private static function render(mixed $value): string
    {
        $rendered = match (true) {
            is_bool($value) => $value ? 'is true' : 'is false',
            is_int($value), is_float($value) => 'is '.$value,
            is_array($value) => 'is an array',
            is_object($value) => 'is an instance of '.$value::class,
            is_string($value) => "is '".mb_substr($value, 0, 40)."'",
            default => 'is '.get_debug_type($value),
        };

        return addcslashes($rendered, "\0..\37\177");
    }
}
