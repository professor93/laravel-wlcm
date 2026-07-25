<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

final class Masker
{
    // Keys matched separator-less, lowercased: "card_number"/"cardNumber" both hit.
    private const array PAN_KEYS = ['cardnumber', 'number', 'pan', 'hpan'];

    private const array STAR_KEYS = ['otp', 'password', 'expiredate'];

    private const array DROP_KEYS = ['apisecret', 'apikey', 'secretkey', 'clientsecret', 'cardtoken', 'consumersecret', 'accesstoken', 'refreshtoken', 'authorization', 'signature'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function mask(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $normalized = null;

            if (is_string($key)) {
                $lower = strtolower($key);
                $normalized = preg_replace('/[^a-z0-9]/', '', $lower) ?? $lower;
            }

            if ($normalized !== null && in_array($normalized, self::DROP_KEYS, true)) {
                continue;
            }

            // Before recursion: PAN hidden in nested value must never be emitted.
            if ($normalized !== null && in_array($normalized, self::PAN_KEYS, true)) {
                if (! is_scalar($value)) {
                    $out[$key] = '***';

                    continue;
                }

                $digits = preg_replace('/\D/', '', (string) $value) ?? '';
                $out[$key] = strlen($digits) >= 13
                    ? substr($digits, 0, 6).str_repeat('*', strlen($digits) - 10).substr($digits, -4)
                    : '***';

                continue;
            }

            if ($normalized !== null && in_array($normalized, self::STAR_KEYS, true)) {
                $out[$key] = '***';

                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::mask($value);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrubProse(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                is_string($value) => self::scrubPans($value),
                is_int($value) => ($s = self::scrubPans((string) $value)) === (string) $value ? $value : $s,
                is_array($value) => self::scrubProse($value),
                default => $value,
            };
        }

        return $data;
    }

    public static function scrubPans(string $text): string
    {
        // Luhn-failing run left whole, never hunted for passing substring: false-positive control beats exhaustiveness.
        $scrubbed = preg_replace_callback(
            '/(?<![0-9])(?:[0-9]{4}(?:[ -][0-9]{4}){3}|[0-9]{13,19})(?![0-9])/',
            static function (array $match): string {
                $run = $match[0];
                $digits = preg_replace('/\D/', '', $run) ?? $run;

                return self::luhn($digits)
                    ? substr($digits, 0, 6).'***'.substr($digits, -4)
                    : $run;
            },
            $text,
        );

        return $scrubbed ?? $text;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
