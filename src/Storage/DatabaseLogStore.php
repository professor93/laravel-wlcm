<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Throwable;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Support\ConfigToggle;

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
            '_wlcm' => ['reshaped' => 'depth'],
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
        if (! $this->enabledForOutbound()) {
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
        if (! $this->enabledForWebhook()) {
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

    /**
     * OUTBOUND: never throws to the caller. A driver value poisoned after resolution used
     * to escape here, ahead of the write's own try/catch, and destroy a payment call whose
     * HTTP round trip to WLCM had already SUCCEEDED — leaving the merchant unable to tell a
     * failed payment from a broken audit config. Report and drop.
     */
    private function enabledForOutbound(): bool
    {
        try {
            return self::configured();
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

    /**
     * INBOUND: throws, and the difference from the outbound rule is the whole point.
     *
     * Nothing has happened upstream when a delivery arrives, so a 500 costs only a
     * redelivery. The controller writes this audit row FIRST inside its try, so a throw
     * here releases the claim and WLCM tries again with nothing dispatched. Swallowing
     * instead fires WebhookReceived and PaymentSucceeded, stamps processed_at, and answers
     * 200 with ZERO audit rows for a money event that really fired — the state `34af392`
     * exists to prevent, and a fourth possibility the audit contract never documented.
     *
     * Applying the outbound rationale to this path was a real defect. Do not re-merge them.
     */
    private function enabledForWebhook(): bool
    {
        return self::configured();
    }

    // Fail-closed at RESOLVE time is unchanged and lives in the provider: it refuses to
    // build a store for an unreadable driver, so a typo present at boot is loud either way.
    private static function configured(): bool
    {
        return ConfigToggle::logsEnabled(config('wlcm.storage.logs', false));
    }
}
