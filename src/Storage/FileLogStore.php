<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use RuntimeException;
use Throwable;

final class FileLogStore implements LogStore
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

        $this->append([
            'connection' => $connection,
            'direction' => 'out',
            'api' => $api,
            'method' => $method,
            'path' => parse_url($url, PHP_URL_PATH) ?: $url,
            'request_body' => $requestBody,
            // Producers key-mask arrays only; string/int scalars arrive PAN-scrubbed but unmasked, so drop.
            'response_body' => is_array($responseBody) ? $responseBody : null,
            'status_code' => $status,
            'duration_ms' => $durationMs,
            'external_id' => $ids['external_id'] ?? null,
            'order_id' => $ids['order_id'] ?? null,
            'payment_id' => $ids['payment_id'] ?? null,
            'transaction_id' => $ids['transaction_id'] ?? null,
            'result' => $result,
            'created_at' => now()->toIso8601String(),
        ]);
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

        $this->append([
            'connection' => $connection,
            'direction' => 'webhook',
            'request_body' => $payload,
            'external_id' => is_scalar($externalId) ? (string) $externalId : null,
            'order_id' => is_scalar($orderId) ? (string) $orderId : null,
            'payment_id' => is_scalar($paymentId) ? (string) $paymentId : null,
            'result' => $result,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function enabled(): bool
    {
        return config('wlcm.storage.logs', false) === 'file';
    }

    /** @param array<string, mixed> $entry */
    private function append(array $entry): void
    {
        try {
            $path = $this->path();
            $directory = dirname($path);

            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create log directory: {$directory}");
            }

            $line = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );

            if (file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException("Unable to append to log file: {$path}");
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function path(): string
    {
        $path = config('wlcm.storage.logs_path');
        $path = is_string($path) ? trim($path) : '';

        return $path !== '' ? $path : storage_path('logs/wlcm.jsonl');
    }
}
