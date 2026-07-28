<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Illuminate\Support\Facades\Log;
use Throwable;
use Uzbek\Wlcm\Support\ConfigToggle;

final class LogChannelLogStore implements LogStore
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
        if (! $this->enabledForOutbound()) {
            return;
        }

        $this->write([
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
        if (! $this->enabledForWebhook()) {
            return;
        }

        $ids = $ids !== [] ? $ids : WebhookLogIds::from($payload);

        $this->write([
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

    /** @param array<string, mixed> $entry */
    private function write(array $entry): void
    {
        try {
            Log::channel($this->channel())->info('wlcm', $entry);
        } catch (Throwable $e) {
            // Swallowed so a broken sink does not break the payment call — but not
            // absolute: report() throws when the log stack cannot be built, the same
            // failure that breaks the sink. Guarded deliberately at the config sites above,
            // bare here, and README says so.
            report($e);
        }
    }

    private function channel(): ?string
    {
        $channel = config('wlcm.storage.logs_channel');
        $channel = is_string($channel) ? trim($channel) : '';

        return $channel !== '' ? $channel : null;
    }
}
