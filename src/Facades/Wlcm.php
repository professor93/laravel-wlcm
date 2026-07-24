<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Facades;

use Illuminate\Support\Facades\Facade;
use Uzbek\Wlcm\Testing\WlcmFake;
use Uzbek\Wlcm\WlcmManager;

/**
 * @method static \Uzbek\Wlcm\Connection connection(?string $name = null)
 * @method static \Uzbek\Wlcm\Partner\PartnerClient partner()
 *
 * @see WlcmManager
 */
final class Wlcm extends Facade
{
    /**
     * Call once per test: the first call's stubs, including the catch-all,
     * take precedence over any later Http::fake()/Wlcm::fake() calls.
     *
     * @param  array<string, array<string, mixed>>  $responses
     */
    public static function fake(array $responses = []): WlcmFake
    {
        return new WlcmFake($responses);
    }

    protected static function getFacadeAccessor(): string
    {
        return WlcmManager::class;
    }
}
