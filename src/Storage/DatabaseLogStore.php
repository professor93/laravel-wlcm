<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Throwable;
use Uzbek\Wlcm\Models\WlcmLog;

final class DatabaseLogStore implements LogStore
{
    /**
     * @param  array<string, mixed>|null  $requestBody
     * @param  array<string, string|null>  $ids
     */
    public function out(
        string $connection,
        string $api,
        string $method,
        string $url,
        ?array $requestBody,
        mixed $responseBody,
        ?int $status,
        int $durationMs,
        array $ids = [],
        string $result = 'ok',
    ): void {
        if (! $this->enabled()) {
            return;
        }

        try {
            WlcmLog::create([
                'connection' => $connection,
                'direction' => 'out',
                'api' => $api,
                'method' => $method,
                'path' => parse_url($url, PHP_URL_PATH) ?: $url,
                'request_body' => $requestBody,
                'response_body' => is_array($responseBody) ? $responseBody : null,
                'status_code' => $status,
                'duration_ms' => $durationMs,
                'external_id' => $ids['external_id'] ?? null,
                'order_id' => $ids['order_id'] ?? null,
                'payment_id' => $ids['payment_id'] ?? null,
                'transaction_id' => $ids['transaction_id'] ?? null,
                'result' => $result,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e); // Audit write must never break payment call.
        }
    }

    /** @param array<string, mixed> $payload */
    public function webhook(string $connection, array $payload, string $result): void
    {
        if (! $this->enabled()) {
            return;
        }

        $externalId = $payload['external_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;
        $paymentId = $payload['payment_id'] ?? null;

        try {
            WlcmLog::create([
                'connection' => $connection,
                'direction' => 'webhook',
                'request_body' => $payload,
                'external_id' => is_scalar($externalId) ? (string) $externalId : null,
                'order_id' => is_scalar($orderId) ? (string) $orderId : null,
                'payment_id' => is_scalar($paymentId) ? (string) $paymentId : null,
                'result' => $result,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e); // Audit write must never break payment call.
        }
    }

    private function enabled(): bool
    {
        $driver = config('wlcm.storage.logs', false);

        return $driver === 'database' || $driver === true || $driver === '1';
    }
}
