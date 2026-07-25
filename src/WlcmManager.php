<?php

declare(strict_types=1);

namespace Uzbek\Wlcm;

use Illuminate\Contracts\Config\Repository;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Partner\PartnerClient;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Support\ConnectionConfig;

final class WlcmManager
{
    /** @var array<string, Connection> */
    private array $connections = [];

    public function __construct(
        private readonly Repository $config,
        private readonly LogStore $logStore,
        private readonly TransactionStore $transactions,
    ) {}

    public function connection(?string $name = null): Connection
    {
        $default = $this->config->get('wlcm.default', 'main');
        /** @var WlcmJsonScalar $default */
        $name ??= (string) $default;

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $config = ConnectionConfig::block($this->config, $name);

        if ($config === null) {
            throw MissingConfigurationException::forKey("wlcm.connections.{$name}");
        }

        return $this->connections[$name] = new Connection($name, $config, $this->logStore, $this->transactions);
    }

    public function partner(): PartnerClient
    {
        return $this->connection()->partner();
    }
}
