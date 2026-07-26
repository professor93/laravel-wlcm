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

    /**
     * Drop a memoised connection so the next call reads config again.
     *
     * Connections are memoised per name and PartnerClient copies base_url, api_key and
     * api_secret in its constructor, which is what makes a request cheap and a long-lived
     * worker blind: under octane, or a queue worker that switches tenant between jobs, a
     * rotated key or a per-tenant base_url is invisible for the rest of the process.
     *
     * The seam is a plain method rather than an Octane event listener on purpose. Octane
     * is not a dependency of this package, its events do not exist without it, and its
     * request boundary is the wrong one anyway: only the host knows where its tenant
     * changes. Call this from Octane::tick, from a tenancy package's own switch event, or
     * from a queue listener — whichever is the real boundary.
     */
    public function forget(string $name): void
    {
        unset($this->connections[$name]);
    }

    /** Drop every memoised connection. See forget(). */
    public function flush(): void
    {
        $this->connections = [];
    }
}
