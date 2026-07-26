<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;
use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Support\ConfigToggle;
use Uzbek\Wlcm\Support\ConnectionConfig;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class TransactionRecorder implements TransactionStore
{
    public function __construct(private readonly Repository $config) {}

    private function enabled(): bool
    {
        return ConfigToggle::enabled($this->config->get('wlcm.storage.transactions', false), 'wlcm.storage.transactions');
    }

    /** @param array<string, mixed> $requestPayload */
    public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void
    {
        if (! $this->enabled()) {
            return;
        }

        $amount = $requestPayload['amount'] ?? 0;
        $provider = $requestPayload['payment_provider'] ?? null;
        /**
         * @var WlcmJsonScalar $amount
         * @var WlcmJsonScalar $provider
         */
        try {
            WlcmTransaction::create(ColumnFit::row([
                'connection' => $connection,
                'api' => 'partner',
                'external_id' => self::scrubbed($response->externalId),
                'order_id' => (string) $response->orderId,
                'transaction_id' => $response->transactionId,
                'amount_tiyin' => (int) $amount,
                'provider' => $provider === null ? null : self::scrubbed((string) $provider),
                'state' => $response->state,
                'state_text' => $response->stateEnum()?->name,
                'meta' => Masker::scrubProse(Masker::mask(['checkout_url' => $response->checkoutUrl, 'cid' => $response->cid])),
            ]));
        } catch (Throwable $e) {
            report($e); // Audit write must never break payment call.
        }
    }

    // Claim-then-dispatch: Fresh rows keep processed_at null until controller
    // finishes events+handler, so crash mid-dispatch reprocesses on redelivery.
    public function applyWebhook(WebhookPayload $payload, string $token): WebhookOutcome
    {
        if (! $this->enabled()) {
            return WebhookOutcome::Fresh;
        }

        return DB::transaction(function () use ($payload, $token): WebhookOutcome {
            $row = WlcmTransaction::query()
                ->where('connection', ColumnFit::value('connection', $payload->connection))
                ->where('payment_id', ColumnFit::value('payment_id', $payload->paymentId))
                ->lockForUpdate()
                ->first();

            if ($row !== null && $row->state === $payload->state) {
                if ($row->processed_at !== null) {
                    return WebhookOutcome::Duplicate;
                }

                // Retaking the claim gives this dispatch its own identity, so the stamp
                // it lands later can only ever hit its own row.
                $row->update(self::claimColumns($token, now()));

                return WebhookOutcome::Fresh;
            }

            // Terminal rows accept only post-success cancel (-2); rest is stale replay.
            $terminal = [PaymentState::Success->value, PaymentState::Cancelled->value, PaymentState::CancelledDuringInit->value];

            if ($row !== null && in_array($row->state, $terminal, true) && $payload->state !== PaymentState::Cancelled->value) {
                return WebhookOutcome::Stale;
            }

            $attributes = ColumnFit::row([
                'api' => 'partner',
                'external_id' => self::scrubbed($payload->externalId),
                'order_id' => $payload->orderId,
                'payment_id' => $payload->paymentId,
                'amount_tiyin' => $payload->amountTiyin(),
                'provider' => self::scrubbed($payload->provider),
                'state' => $payload->state,
                'state_text' => $payload->stateEnum()?->name,
                ...self::claimColumns($token, now()),
            ]);

            if ($row !== null) {
                $row->update($attributes);

                return WebhookOutcome::Fresh;
            }

            $checkoutRow = WlcmTransaction::query()
                ->where('connection', ColumnFit::value('connection', $payload->connection))
                ->where('order_id', ColumnFit::value('order_id', $payload->orderId))
                ->whereNull('payment_id')
                ->lockForUpdate()
                ->first();

            if ($checkoutRow !== null) {
                $checkoutRow->update($attributes);

                return WebhookOutcome::Fresh;
            }

            WlcmTransaction::query()->create(
                ['connection' => ColumnFit::value('connection', $payload->connection), ...$attributes],
            );

            return WebhookOutcome::Fresh;
        });
    }

    public function markProcessed(WebhookPayload $payload, string $token): void
    {
        if (! $this->enabled()) {
            return;
        }

        WlcmTransaction::query()
            ->where('connection', ColumnFit::value('connection', $payload->connection))
            ->where('payment_id', ColumnFit::value('payment_id', $payload->paymentId))
            // Own claim only. A later transition may already hold this row, and stamping
            // ITS claim processed swallows that delivery's redelivery for good.
            ->where('dispatch_token', $token)
            ->update(['processed_at' => now(), 'dispatch_token' => null, 'claimed_at' => null]);
    }

    public function claimSubscription(SubscriptionWebhookPayload $payload, string $token): WebhookOutcome
    {
        if (! $this->enabled()) {
            return WebhookOutcome::Fresh;
        }

        $key = $this->subscriptionKey($payload);

        try {
            WlcmTransaction::query()->create(ColumnFit::row([
                'connection' => $payload->connection,
                'api' => 'subscription',
                'external_id' => self::scrubbed($payload->externalOrderId),
                'state_text' => self::scrubbed($payload->event),
                // Keyed hmac over the raw values: unforgeable without the secret, and
                // scrubbing the preimage would only collide keys without hiding anything.
                'delivery_key' => $key,
                ...self::claimColumns($token, now()),
            ]));

            return WebhookOutcome::Fresh;
        } catch (UniqueConstraintViolationException $e) {
            $existing = WlcmTransaction::query()
                ->where('connection', ColumnFit::value('connection', $payload->connection))
                ->where('delivery_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                throw $e;
            }

            if ($existing->processed_at !== null) {
                return WebhookOutcome::Duplicate;
            }

            $existing->update(self::claimColumns($token, now()));

            return WebhookOutcome::Fresh;
        }
    }

    public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload, string $token): void
    {
        if (! $this->enabled()) {
            return;
        }

        WlcmTransaction::query()
            ->where('connection', ColumnFit::value('connection', $payload->connection))
            ->where('delivery_key', $this->subscriptionKey($payload))
            ->where('dispatch_token', $token)
            ->update(['processed_at' => now(), 'dispatch_token' => null, 'claimed_at' => null]);
    }

    // Keyed, not bare. Event and timestamp sit readable in the same delivery and the
    // reference column keeps only 6+4 of a pan, so a bare hash hands the rest back to
    // anyone who can read the row and count to a million.
    private function subscriptionKey(SubscriptionWebhookPayload $payload): string
    {
        return hash_hmac(
            'sha256',
            "{$payload->externalOrderId}:{$payload->event}:{$payload->timestamp}",
            $this->webhookSecret($payload->connection),
        );
    }

    // Same lookup VerifyWebhookSignature already ran for this delivery, so on the webhook
    // path the secret is guaranteed present. Missing = throw; a bare-hash fallback would
    // quietly rebuild the oracle.
    private function webhookSecret(?string $connection): string
    {
        $default = $this->config->get('wlcm.default', 'main');
        /** @var WlcmJsonScalar $default */
        $connection ??= (string) $default;
        $block = ConnectionConfig::block($this->config, $connection);
        $secret = $block === null ? null : Arr::get($block, 'partner.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw MissingConfigurationException::forKey("wlcm.connections.{$connection}.partner.webhook_secret");
        }

        return $secret;
    }

    // Only merchant free-text can hide a pan, and wlcm_logs scrubs its copy of the same
    // field. Server-minted ids are never scrubbed: the scrub is lossy, and two real ids
    // can collapse onto one stored value.
    private static function scrubbed(?string $value): ?string
    {
        return $value === null ? null : Masker::scrubPans($value);
    }

    /**
     * The random token is what guarantees a matched row is a CHANGED row. Mysql counts
     * changed rows, and a 0 reads as "someone else owns the dispatch" — an event that
     * never fires. Never make this deterministic, boolean, or derived from a request id.
     *
     * @return array<string, mixed>
     */
    private static function claimColumns(string $token, Carbon $now): array
    {
        return ['dispatch_token' => $token, 'claimed_at' => $now, 'processed_at' => null];
    }
}
