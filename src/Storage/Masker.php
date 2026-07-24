<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

final class Masker
{
    // Keys compared separator-less and lowercased: "card_number"/"cardNumber" both hit.
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

            // Before recursion: PAN hidden in a nested value must never be emitted.
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
}
