<?php

declare(strict_types=1);

namespace Uzbek\Wlcm;

use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Partner\OnboardingClient;
use Uzbek\Wlcm\Partner\PartnerClient;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\TransactionStore;

/**
 * @phpstan-import-type PartnerClientConfigShape from PartnerClient
 * @phpstan-import-type WlcmHttpShape from PartnerClient
 * @phpstan-import-type OnboardingClientConfigShape from OnboardingClient
 * @phpstan-import-type OnboardingHttpShape from OnboardingClient
 */
final class Connection
{
    private ?PartnerClient $partner = null;

    private ?OnboardingClient $onboarding = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        public readonly string $name,
        private readonly array $config,
        private readonly LogStore $logStore,
        private readonly TransactionStore $transactions,
    ) {}

    public function partner(): PartnerClient
    {
        if ($this->partner === null) {
            $config = $this->section('partner');
            $this->requireKeys('partner', $config, ['base_url', 'api_key', 'api_secret']);
            $http = $this->httpConfig();
            /**
             * @var PartnerClientConfigShape $config
             * @var WlcmHttpShape $http
             */
            $this->partner = new PartnerClient($this->name, $config, $http, $this->logStore, $this->transactions);
        }

        return $this->partner;
    }

    /**
     * Credential-less by design: onboarding is how a partner GETS its key and secret,
     * so requiring them here would make the endpoint unreachable. Only base_url is
     * needed. partner() keeps its full requirement — this does not relax that.
     */
    public function onboarding(): OnboardingClient
    {
        if ($this->onboarding === null) {
            $config = $this->section('partner');
            $this->requireKeys('partner', $config, ['base_url']);
            $http = $this->httpConfig();
            /**
             * @var OnboardingClientConfigShape $config
             * @var OnboardingHttpShape $http
             */
            $this->onboarding = new OnboardingClient($this->name, $config, $http, $this->logStore);
        }

        return $this->onboarding;
    }

    /** @return array<string, mixed> */
    public function section(string $key): array
    {
        $section = $this->config[$key] ?? null;

        if (! is_array($section) || $section === []) {
            throw MissingConfigurationException::forKey("wlcm.connections.{$this->name}.{$key}");
        }

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $keys
     */
    private function requireKeys(string $section, array $config, array $keys): void
    {
        foreach ($keys as $key) {
            $value = $config[$key] ?? null;

            if (! is_string($value) || $value === '') {
                throw MissingConfigurationException::forKey("wlcm.connections.{$this->name}.{$section}.{$key}");
            }
        }
    }

    /** @return array<string, mixed> */
    private function httpConfig(): array
    {
        $http = $this->config['http'] ?? null;

        $result = is_array($http) ? $http : ['timeout' => 15, 'connect_timeout' => 5, 'get_retries' => 2];

        /** @var array<string, mixed> $result */
        return $result;
    }
}
