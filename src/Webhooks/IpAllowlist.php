<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Symfony\Component\HttpFoundation\IpUtils;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;

/**
 * Matching for `wlcm.webhook.allowed_ips`. Accepts exact addresses, CIDR blocks and
 * inclusive dash ranges, as a config array or as the CSV string an `.env` can carry.
 *
 * Exact and CIDR go through Symfony's IpUtils rather than hand-rolled maths — it already
 * handles v4, v6 and the mapped forms in between. Only the dash range is ours, because
 * IpUtils has no notion of one.
 *
 * A malformed entry THROWS. The quiet failure is the dangerous one: an entry that can
 * never match leaves the operator believing an address is allowed while every delivery
 * is refused, and the usual response to that is to widen the list until something works.
 */
final class IpAllowlist
{
    /** Is the allowlist switched on at all? Absent, empty or whitespace means off. */
    public static function configured(mixed $value): bool
    {
        return self::entries($value) !== [];
    }

    public static function allows(?string $clientIp, mixed $value): bool
    {
        $entries = self::entries($value);

        // Validate EVERY entry before matching any. Lazily validating short-circuits on
        // the first match, so a typo further down the list stays invisible until the day
        // an address that should have matched it is refused — silently, which is the
        // failure this class exists to prevent.
        foreach ($entries as $entry) {
            self::validate($entry);
        }

        if ($clientIp === null || trim($clientIp) === '') {
            return false;
        }

        foreach ($entries as $entry) {
            if (self::matches($clientIp, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function validate(string $entry): void
    {
        $addresses = str_contains($entry, '-')
            ? array_map(trim(...), explode('-', $entry, 2))
            : [str_contains($entry, '/') ? explode('/', $entry, 2)[0] : $entry];

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw MissingConfigurationException::forKey('wlcm.webhook.allowed_ips', 'an IP, CIDR block or start-end range', $entry);
            }
        }
    }

    /** @return list<string> */
    private static function entries(mixed $value): array
    {
        // An env var is always a string, so CSV is the only way to express a list there.
        $raw = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);

        $out = [];

        foreach ($raw as $entry) {
            if (! is_scalar($entry)) {
                continue;
            }

            $trimmed = trim((string) $entry);

            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    private static function matches(string $clientIp, string $entry): bool
    {
        if (str_contains($entry, '-')) {
            return self::inRange($clientIp, $entry);
        }

        // Already validated above; IpUtils would otherwise treat an unparseable entry as
        // a quiet non-match.
        return IpUtils::checkIp($clientIp, $entry);
    }

    private static function inRange(string $clientIp, string $entry): bool
    {
        [$start, $end] = array_pad(explode('-', $entry, 2), 2, '');

        $startPacked = @inet_pton(trim($start));
        $endPacked = @inet_pton(trim($end));
        $clientPacked = @inet_pton($clientIp);

        // Packed form compares correctly with strcmp across octets and across v6 groups,
        // which decimal-per-octet maths does not. A mixed-family range never matches
        // rather than comparing bytes of different widths.
        if ($clientPacked === false || $startPacked === false || $endPacked === false) {
            return false;
        }

        if (strlen($clientPacked) !== strlen($startPacked) || strlen($startPacked) !== strlen($endPacked)) {
            return false;
        }

        return strcmp($clientPacked, $startPacked) >= 0 && strcmp($clientPacked, $endPacked) <= 0;
    }
}
