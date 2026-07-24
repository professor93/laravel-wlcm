<?php

declare(strict_types=1);

namespace Uzbek\Wlcm;

use Illuminate\Contracts\Config\Repository;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Partner\PartnerClient;

final class WlcmManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    public function __construct(private readonly Repository $config) {}

    public function connection(?string $name = null): Connection
    {
        $default = $this->config->get('wlcm.default', 'main');
        /** @var WlcmJsonScalar $default */
        $name ??= (string) $default;

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $config = $this->config->get("wlcm.connections.{$name}");

        if (! is_array($config)) {
            throw MissingConfigurationException::forKey("wlcm.connections.{$name}");
        }

        /** @var array<string, mixed> $config */
        return $this->connections[$name] = new Connection($name, $config);
    }

    public function partner(): PartnerClient
    {
        return $this->connection()->partner();
    }
}
