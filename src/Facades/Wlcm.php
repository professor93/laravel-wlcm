<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Facades;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Facade;
use Uzbek\Wlcm\Testing\WlcmFake;
use Uzbek\Wlcm\WlcmManager;

/**
 * @method static \Uzbek\Wlcm\Connection connection(?string $name = null)
 * @method static \Uzbek\Wlcm\Partner\PartnerClient partner()
 * @method static void forget(string $name)
 * @method static void flush()
 *
 * @see WlcmManager
 */
final class Wlcm extends Facade
{
    /**
     * Call once per test: first call's stubs, including catch-all, take
     * precedence over any later Http::fake()/Wlcm::fake() call.
     *
     * @param  array<string, array<string, mixed>|PromiseInterface>  $responses
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
