<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

final class Masker
{
    private const array PAN_KEYS = ['card_number', 'cardnumber', 'number', 'pan', 'hpan'];

    private const array STAR_KEYS = ['otp', 'password', 'expire_date', 'expiredate'];

    private const array DROP_KEYS = ['api_secret', 'api_key', 'secret_key', 'client_secret', 'card_token', 'consumer_secret', 'access_token', 'refresh_token', 'authorization', 'signature'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function mask(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $normalized = is_string($key) ? strtolower($key) : $key;

            if (is_string($normalized) && in_array($normalized, self::DROP_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::mask($value);

                continue;
            }

            if (is_string($normalized) && in_array($normalized, self::PAN_KEYS, true) && is_scalar($value)) {
                $digits = preg_replace('/\D/', '', (string) $value) ?? '';
                $out[$key] = strlen($digits) >= 13
                    ? substr($digits, 0, 6).str_repeat('*', strlen($digits) - 10).substr($digits, -4)
                    : '***';

                continue;
            }

            if (is_string($normalized) && in_array($normalized, self::STAR_KEYS, true)) {
                $out[$key] = '***';

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
