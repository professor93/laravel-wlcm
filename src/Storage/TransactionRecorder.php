<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
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
                'order_id' => self::scrubbed((string) $response->orderId),
                'transaction_id' => self::scrubbed($response->transactionId),
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
    public function applyWebhook(WebhookPayload $payload): WebhookOutcome
    {
        if (! $this->enabled()) {
            return WebhookOutcome::Fresh;
        }

        return DB::transaction(function () use ($payload): WebhookOutcome {
            $row = WlcmTransaction::query()
                ->where('connection', ColumnFit::value('connection', $payload->connection))
                ->where('payment_id', self::key('payment_id', $payload->paymentId))
                ->lockForUpdate()
                ->first();

            if ($row !== null && $row->state === $payload->state) {
                return $row->processed_at === null ? WebhookOutcome::Fresh : WebhookOutcome::Duplicate;
            }

            // Terminal rows accept only post-success cancel (-2); rest is stale replay.
            $terminal = [PaymentState::Success->value, PaymentState::Cancelled->value, PaymentState::CancelledDuringInit->value];

            if ($row !== null && in_array($row->state, $terminal, true) && $payload->state !== PaymentState::Cancelled->value) {
                return WebhookOutcome::Stale;
            }

            $attributes = ColumnFit::row([
                'api' => 'partner',
                'external_id' => self::scrubbed($payload->externalId),
                'order_id' => self::scrubbed($payload->orderId),
                'payment_id' => self::scrubbed($payload->paymentId),
                'amount_tiyin' => $payload->amountTiyin(),
                'provider' => self::scrubbed($payload->provider),
                'state' => $payload->state,
                'state_text' => $payload->stateEnum()?->name,
                'processed_at' => null,
            ]);

            if ($row !== null) {
                $row->update($attributes);

                return WebhookOutcome::Fresh;
            }

            $checkoutRow = WlcmTransaction::query()
                ->where('connection', ColumnFit::value('connection', $payload->connection))
                ->where('order_id', self::key('order_id', $payload->orderId))
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

    public function markProcessed(WebhookPayload $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        WlcmTransaction::query()
            ->where('connection', ColumnFit::value('connection', $payload->connection))
            ->where('payment_id', self::key('payment_id', $payload->paymentId))
            ->update(['processed_at' => now()]);
    }

    public function claimSubscription(SubscriptionWebhookPayload $payload): WebhookOutcome
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

            return $existing->processed_at === null ? WebhookOutcome::Fresh : WebhookOutcome::Duplicate;
        }
    }

    public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        WlcmTransaction::query()
            ->where('connection', ColumnFit::value('connection', $payload->connection))
            ->where('delivery_key', $this->subscriptionKey($payload))
            ->update(['processed_at' => now()]);
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

    // Upstream echoes merchant free-text back into ids. wlcm_logs scrubs its own copy of
    // these columns, so this table scrubs too — one pan must not survive in either place.
    private static function scrubbed(?string $value): ?string
    {
        return $value === null ? null : Masker::scrubPans($value);
    }

    // Lookups go through the same scrub-then-cut the write did, or a stored row is lost.
    private static function key(string $column, ?string $value): ?string
    {
        return ColumnFit::value($column, self::scrubbed($value));
    }
}
