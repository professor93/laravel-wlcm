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
        if (! (bool) config('wlcm.storage.logs', false)) {
            return;
        }

        try {
            WlcmLog::create([
                'connection' => $connection,
                'direction' => 'out',
                'api' => $api,
                'method' => $method,
                'path' => parse_url($url, PHP_URL_PATH) ?: $url,
                'request_body' => $requestBody === null ? null : Masker::mask($requestBody),
                'response_body' => is_array($responseBody) ? Masker::mask($responseBody) : null,
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
            report($e); // Audit write must never break a payment call.
        }
    }

    /** @param array<string, mixed> $payload */
    public function webhook(string $connection, array $payload, string $result): void
    {
        if (! (bool) config('wlcm.storage.logs', false)) {
            return;
        }

        $externalId = $payload['external_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;
        $paymentId = $payload['payment_id'] ?? null;
        /**
         * @var WlcmJsonScalar $externalId
         * @var WlcmJsonScalar $orderId
         * @var WlcmJsonScalar $paymentId
         */
        try {
            WlcmLog::create([
                'connection' => $connection,
                'direction' => 'webhook',
                'request_body' => Masker::mask($payload),
                'external_id' => $externalId === null ? null : (string) $externalId,
                'order_id' => $orderId === null ? null : (string) $orderId,
                'payment_id' => $paymentId === null ? null : (string) $paymentId,
                'result' => $result,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e); // Audit write must never break a payment call.
        }
    }
}
