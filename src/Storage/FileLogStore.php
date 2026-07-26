<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use RuntimeException;
use Throwable;
use Uzbek\Wlcm\Support\ConfigToggle;

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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $ids
     */
    public function webhook(string $connection, array $payload, string $result, array $ids = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ids = $ids !== [] ? $ids : WebhookLogIds::from($payload);

        $this->append([
            'connection' => $connection,
            'direction' => 'webhook',
            'request_body' => $payload,
            'external_id' => $ids['external_id'] ?? null,
            'order_id' => $ids['order_id'] ?? null,
            'payment_id' => $ids['payment_id'] ?? null,
            'result' => $result,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    private function enabled(): bool
    {
        // Never throws to the caller. A driver value poisoned AFTER resolution used to
        // escape here, ahead of the write's own try/catch, and destroy a payment call
        // whose HTTP round trip to WLCM had already succeeded — leaving the merchant
        // unable to tell a failed payment from a broken audit config. Fail-closed still
        // applies where it is safe: the provider refuses to RESOLVE an unreadable driver,
        // so a typo present at boot is loud. Past that point, report and drop.
        try {
            return ConfigToggle::logsEnabled(config('wlcm.storage.logs', false));
        } catch (Throwable $e) {
            // report() itself can throw when the app's log channel is down, and nothing
            // here may reach the caller.
            try {
                report($e);
            } catch (Throwable) {
                //
            }

            return false;
        }
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
