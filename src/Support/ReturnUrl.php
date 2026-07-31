<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;

/**
 * Where the payer comes back to after paying.
 *
 * Resolution order: the explicit argument, then the connection's own setting, then the
 * global one, then nothing — in which case the key is omitted from the payload entirely
 * and WLCM applies its own default. Omission is not the same as sending an empty string,
 * which the server accepts and which means the payer never comes back.
 */
final class ReturnUrl
{
    public static function resolve(Repository $config, string $connection, ?string $explicit): ?string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            return self::expand($explicit);
        }

        // Per-connection FIRST, and read as an array key rather than a config path:
        // `shop.uz` interpolated into a dotted path reads as connections[shop][uz] and
        // silently finds nothing — the bug class fix 7e49c91 addressed.
        $block = ConnectionConfig::block($config, $connection);
        $perConnection = is_array($block['checkout'] ?? null) ? ($block['checkout']['return_url'] ?? null) : null;

        if (is_string($perConnection) && trim($perConnection) !== '') {
            return self::expand($perConnection);
        }

        $global = $config->get('wlcm.checkout.return_url');

        if (is_string($global) && trim($global) !== '') {
            return self::expand($global);
        }

        return null;
    }

    /**
     * A value with no scheme that names a registered route is resolved through route().
     *
     * Deliberately narrow. Anything carrying `://` is a url and is passed through
     * untouched, so a real url can never be mistaken for a route name; and a name that
     * is not registered is returned verbatim rather than throwing, because a merchant
     * whose route is only defined in another context should not have their checkout die.
     */
    private static function expand(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '://') || str_starts_with($value, '/')) {
            return $value;
        }

        return Route::has($value) ? route($value) : $value;
    }
}
