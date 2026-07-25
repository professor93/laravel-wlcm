<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Exceptions\ConnectionException;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\Masker;
use Uzbek\Wlcm\Storage\TransactionRecorder;

final class PartnerClient
{
    private ?RequestSigner $signer = null;

    /**
     * @param  PartnerClientConfigShape  $config
     * @param  WlcmHttpShape  $http
     */
    public function __construct(
        public readonly string $connectionName,
        protected readonly array $config,
        protected readonly array $http,
        protected readonly LogStore $logStore,
        protected readonly TransactionRecorder $transactions,
    ) {}

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->url($path, $query);
        $attempts = 1 + max(0, (int) ($this->http['get_retries'] ?? 2));
        $lastTransport = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $this->send('GET', $url, null);
            } catch (ConnectionException $e) {
                $lastTransport = $e;
                usleep(100_000 * ($i + 1));
            }
        }

        throw $lastTransport;
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postUnsigned(string $path, array $payload = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->send('POST', $this->url($path), $body, signed: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function patch(string $path, array $payload = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->send('PATCH', $this->url($path), $body);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query = []): array
    {
        return $this->send('DELETE', $this->url($path, $query), null);
    }

    public function splitAmountUnit(): string
    {
        return (string) ($this->config['split_amount_unit'] ?? 'sum');
    }

    public function logStore(): LogStore
    {
        return $this->logStore;
    }

    public function transactions(): TransactionRecorder
    {
        return $this->transactions;
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $url, ?string $body, bool $signed = true): array
    {
        $request = $this->pendingRequest();

        if ($signed) {
            $timestamp = (string) now()->getTimestampMs();

            $request = $request->withHeaders([
                'X-API-Key' => (string) $this->config['api_key'],
                'X-Timestamp' => $timestamp,
                'X-Signature' => $this->signer()->sign($method, $url, $timestamp, $body ?? ''),
            ]);
        }

        $started = hrtime(true);

        try {
            $response = $body === null
                ? $request->send($method, $url)
                : $request->withBody($body, 'application/json')->send($method, $url);
        } catch (HttpConnectionException $e) {
            $this->logStore->out($this->connectionName, 'partner', $method, $url, $this->maskedBody($body), null, null, $this->elapsedMs($started), result: 'error');

            throw new ConnectionException($e->getMessage(), $this->connectionName, $method, $url, previous: $e);
        }

        $durationMs = $this->elapsedMs($started);
        $json = $response->json();

        $ids = [];

        if (is_array($json)) {
            /** @var array<string, mixed> $json */
            $externalId = $json['external_id'] ?? null;
            $orderId = $json['order_id'] ?? null;
            $paymentId = $json['payment_id'] ?? null;
            $transactionId = $json['transaction_id'] ?? null;
            /**
             * @var WlcmJsonScalar $externalId
             * @var WlcmJsonScalar $orderId
             * @var WlcmJsonScalar $paymentId
             * @var WlcmJsonScalar $transactionId
             */
            $ids = [
                'external_id' => $externalId === null ? null : (string) $externalId,
                'order_id' => $orderId === null ? null : (string) $orderId,
                'payment_id' => $paymentId === null ? null : (string) $paymentId,
                'transaction_id' => $transactionId === null ? null : (string) $transactionId,
            ];
        }

        $this->logStore->out(
            $this->connectionName,
            'partner',
            $method,
            $url,
            $this->maskedBody($body),
            is_array($json) ? Masker::mask($json) : $json,
            $response->status(),
            $durationMs,
            ids: $ids,
            result: $response->successful() && is_array($json) ? 'ok' : 'error',
        );

        return ErrorMapper::handle($response, $this->connectionName, $method, $url);
    }

    /** @return array<string, mixed>|null */
    private function maskedBody(?string $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $masked */
        $masked = Masker::mask($decoded);

        return $masked;
    }

    private function elapsedMs(int|float $startedNs): int
    {
        return (int) ((hrtime(true) - $startedNs) / 1_000_000);
    }

    private function pendingRequest(): PendingRequest
    {
        return Http::timeout((int) ($this->http['timeout'] ?? 15))
            ->connectTimeout((int) ($this->http['connect_timeout'] ?? 5))
            ->acceptJson();
    }

    /** @param array<string, int|string> $query */
    private function url(string $path, array $query = []): string
    {
        $base = rtrim((string) $this->config['base_url'], '/');
        $url = $base.'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function signer(): RequestSigner
    {
        return $this->signer ??= new RequestSigner((string) $this->config['api_secret']);
    }

    public function checkout(): Resources\Checkout
    {
        return new Resources\Checkout($this);
    }

    public function payments(): Resources\Payments
    {
        return new Resources\Payments($this);
    }

    public function partners(): Resources\Partners
    {
        return new Resources\Partners($this);
    }

    public function fiscalization(): Resources\Fiscalization
    {
        return new Resources\Fiscalization($this);
    }

    public function providers(): Resources\Providers
    {
        return new Resources\Providers($this);
    }

    public function orders(): Resources\Orders
    {
        return new Resources\Orders($this);
    }

    public function webhooks(): Resources\Webhooks
    {
        return new Resources\Webhooks($this);
    }

    public function subscriptions(): Resources\Subscriptions
    {
        return new Resources\Subscriptions($this);
    }
}
