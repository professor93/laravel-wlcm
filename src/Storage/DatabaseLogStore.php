<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Throwable;
use Uzbek\Wlcm\Models\WlcmLog;

final class DatabaseLogStore implements LogStore
{
    // Mysql refuses a json document deeper than 100 levels (error 3157). That write is
    // swallowed by design, so a delivery that dispatched and fired its events could leave
    // no audit row at all — while postgres logged the same body fine. Capped BEFORE the
    // write, not retried after it, so every engine stores the same shape and the cap is
    // observable rather than dependent on an engine raising an error.
    private const int MAX_JSON_DEPTH = 64;

    /**
     * @param  array<array-key, mixed>|null  $body
     * @return array<array-key, mixed>|null
     */
    private static function fitDepth(?array $body): ?array
    {
        if ($body === null || self::depth($body) <= self::MAX_JSON_DEPTH) {
            return $body;
        }

        // Nothing is discarded: the whole body survives as one encoded string, and the
        // marker says why the shape changed.
        return [
            'wlcm_reshaped' => 'depth',
            'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ];
    }

    /** @param array<array-key, mixed> $value */
    private static function depth(array $value): int
    {
        $deepest = 1;

        foreach ($value as $item) {
            if (is_array($item)) {
                $deepest = max($deepest, 1 + self::depth($item));
            }
        }

        return $deepest;
    }

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
            WlcmLog::create(ColumnFit::row([
                'connection' => $connection,
                'direction' => 'out',
                'api' => $api,
                'method' => $method,
                'path' => parse_url($url, PHP_URL_PATH) ?: $url,
                'request_body' => self::fitDepth($requestBody),
                'response_body' => self::fitDepth(is_array($responseBody) ? $responseBody : null),
                'status_code' => $status,
                'duration_ms' => $durationMs,
                'external_id' => $ids['external_id'] ?? null,
                'order_id' => $ids['order_id'] ?? null,
                'payment_id' => $ids['payment_id'] ?? null,
                'transaction_id' => $ids['transaction_id'] ?? null,
                'result' => $result,
                'created_at' => now(),
            ]));
        } catch (Throwable $e) {
            report($e); // Audit write must never break payment call.
        }
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

        try {
            WlcmLog::create(ColumnFit::row([
                'connection' => $connection,
                'direction' => 'webhook',
                'request_body' => self::fitDepth($payload),
                'external_id' => $ids['external_id'] ?? null,
                'order_id' => $ids['order_id'] ?? null,
                'payment_id' => $ids['payment_id'] ?? null,
                'result' => $result,
                'created_at' => now(),
            ]));
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
