<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Fixtures;

use Illuminate\Routing\RouteCollection;

/**
 * Counts the scans of a method's table. `WebhookRoute::shadow()` asks for it exactly once
 * per call, so this counts watch passes — the boot cost every merchant pays whether or not
 * they have a conflict.
 */
final class CountingRouteCollection extends RouteCollection
{
    /** @var array<string, int> */
    public array $scans = [];

    public function get($method = null)
    {
        if ($method !== null) {
            $this->scans[$method] = ($this->scans[$method] ?? 0) + 1;
        }

        return parent::get($method);
    }
}
