<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit;

use Uzbek\Wlcm\Connection;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class ManagerTest extends TestCase
{
    public function test_default_connection_resolves(): void
    {
        $this->assertInstanceOf(Connection::class, Wlcm::connection());
    }

    public function test_named_connection_caches(): void
    {
        $this->assertSame(Wlcm::connection('main'), Wlcm::connection('main'));
    }

    public function test_unknown_connection_throws(): void
    {
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.connections.nope');
        Wlcm::connection('nope');
    }

    public function test_null_leaf_config_throws_exact_key(): void
    {
        config()->set('wlcm.connections.main.partner.api_secret', null);
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.connections.main.partner.api_secret');
        Wlcm::connection('main')->partner();
    }
}
