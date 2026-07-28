<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Support;

use Illuminate\Contracts\Config\Repository;

final class ConnectionConfig
{
    /**
     * A connection name is an array key, never a config path: `shop.uz`
     * interpolated into one reads as connections[shop][uz] and finds nothing.
     *
     * @return array<string, mixed>|null
     */
    public static function block(Repository $config, string $name): ?array
    {
        $connections = $config->get('wlcm.connections');

        if (! is_array($connections)) {
            return null;
        }

        $block = $connections[$name] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($block) ? $block : null;
    }
}
