<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Exceptions\ConnectionException;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\Masker;

/**
 * Unsigned transport for the onboarding endpoints, which authenticate by a `?token=`
 * query parameter and no HMAC.
 *
 * Separate from PartnerClient on purpose: it holds NO api key and NO secret and owns no
 * signer, so it cannot emit a broken signature for a partner that does not exist yet.
 * PartnerClient refuses to construct without credentials, which is correct for every
 * signed call and exactly wrong for the call that mints them.
 *
 * Its send() is smaller than PartnerClient's by design — no GET retries, no transaction
 * store, no correlation-id extraction. Onboarding is two calls that touch no money path.
 * That duplicates roughly thirty lines rather than extracting a shared transport out of
 * PartnerClient, which is heavily reviewed and sits on the payment path. If a third
 * unsigned caller ever appears, extract then.
 *
 * @phpstan-type OnboardingClientConfigShape array{base_url: WlcmJsonScalar}
 * @phpstan-type OnboardingHttpShape array{timeout?: WlcmJsonScalar, connect_timeout?: WlcmJsonScalar}
 */
final readonly class OnboardingClient
{
    /**
     * @param  OnboardingClientConfigShape  $config
     * @param  OnboardingHttpShape  $http
     */
    public function __construct(
        public string $connectionName,
        private array $config,
        private array $http,
        private LogStore $logStore,
    ) {}

    public function onboarding(): Resources\Onboarding
    {
        return new Resources\Onboarding($this);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $this->url($path, $query), null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = [], array $query = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->send('POST', $this->url($path, $query), $body);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $url, ?string $body): array
    {
        $request = Http::timeout((int) ($this->http['timeout'] ?? 15))
            ->connectTimeout((int) ($this->http['connect_timeout'] ?? 5))
            ->acceptJson();

        $started = hrtime(true);

        try {
            $response = $body === null
                ? $request->send($method, $url)
                : $request->withBody($body, 'application/json')->send($method, $url);
        } catch (HttpConnectionException $e) {
            $this->logStore->out($this->connectionName, 'partner', $method, $url, null, null, null, $this->elapsedMs($started), result: 'error');

            // The token rides both the url and the Guzzle message, which appends
            // " for {uri}" to every cURL error.
            throw new ConnectionException(
                Masker::redactQuery(Masker::scrubPans($e->getMessage())),
                $this->connectionName,
                $method,
                Masker::redactQuery(Masker::scrubPans($url)),
                previous: $e,
            );
        }

        $json = $response->json();

        // No id extraction: onboarding responses carry none of the correlation columns,
        // and the one field they do carry — api_secret — is dropped by Masker::mask.
        $loggedBody = is_array($json)
            ? Masker::scrubProse(Masker::mask(ErrorMapper::maskDetailInputs($json)))
            : (is_string($json) ? Masker::scrubPans($json) : $json);

        $this->logStore->out(
            $this->connectionName,
            'partner',
            $method,
            $url,
            $this->maskedBody($body),
            $loggedBody,
            $response->status(),
            $this->elapsedMs($started),
            result: $response->successful() && is_array($json) ? 'ok' : 'error',
        );

        return ErrorMapper::handle($response, $this->connectionName, $method, $url);
    }

    /** @return array<string, mixed>|null */
    private function maskedBody(?string $body): ?array
    {
        $decoded = $body === null ? null : json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $masked */
        $masked = Masker::scrubProse(Masker::mask($decoded));

        return $masked;
    }

    /** @param array<string, int|string> $query */
    private function url(string $path, array $query = []): string
    {
        // ltrim only, never rtrim: /partners/onboarding without its trailing slash
        // resolves to a DIFFERENT, signed route that answers 401.
        $url = rtrim((string) $this->config['base_url'], '/').'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function elapsedMs(int|float $startedNs): int
    {
        return (int) ((hrtime(true) - $startedNs) / 1_000_000);
    }
}
