<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;
use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class TransactionRecorder
{
    private function enabled(): bool
    {
        return (bool) config('wlcm.storage.transactions', false);
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
            WlcmTransaction::create([
                'connection' => $connection,
                'api' => 'partner',
                'external_id' => $response->externalId,
                'order_id' => (string) $response->orderId,
                'transaction_id' => $response->transactionId,
                'amount_tiyin' => (int) $amount,
                'provider' => $provider === null ? null : (string) $provider,
                'state' => $response->state,
                'state_text' => $response->stateEnum()?->name,
                'meta' => Masker::mask(['checkout_url' => $response->checkoutUrl, 'cid' => $response->cid]),
            ]);
        } catch (Throwable $e) {
            report($e); // Audit write must never break a payment call.
        }
    }

    // Claim-then-dispatch: Fresh rows keep processed_at null until the controller
    // finishes events+handler, so a crash mid-dispatch reprocesses on redelivery.
    public function applyWebhook(WebhookPayload $payload): WebhookOutcome
    {
        if (! $this->enabled()) {
            return WebhookOutcome::Fresh;
        }

        return DB::transaction(function () use ($payload): WebhookOutcome {
            $row = WlcmTransaction::query()
                ->where('connection', $payload->connection)
                ->where('payment_id', $payload->paymentId)
                ->lockForUpdate()
                ->first();

            if ($row !== null && $row->state === $payload->state) {
                return $row->processed_at === null ? WebhookOutcome::Fresh : WebhookOutcome::Duplicate;
            }

            // Terminal rows accept only a post-success cancel (-2); everything else is a stale replay.
            $terminal = [PaymentState::Success->value, PaymentState::Cancelled->value, PaymentState::CancelledDuringInit->value];

            if ($row !== null && in_array($row->state, $terminal, true) && $payload->state !== PaymentState::Cancelled->value) {
                return WebhookOutcome::Stale;
            }

            $attributes = [
                'api' => 'partner',
                'external_id' => $payload->externalId,
                'order_id' => $payload->orderId,
                'payment_id' => $payload->paymentId,
                'amount_tiyin' => $payload->amountTiyin(),
                'provider' => $payload->provider,
                'state' => $payload->state,
                'state_text' => $payload->stateEnum()?->name,
                'processed_at' => null,
            ];

            if ($row !== null) {
                $row->update($attributes);

                return WebhookOutcome::Fresh;
            }

            $checkoutRow = WlcmTransaction::query()
                ->where('connection', $payload->connection)
                ->where('order_id', $payload->orderId)
                ->whereNull('payment_id')
                ->lockForUpdate()
                ->first();

            if ($checkoutRow !== null) {
                $checkoutRow->update($attributes);

                return WebhookOutcome::Fresh;
            }

            WlcmTransaction::query()->create(
                ['connection' => $payload->connection, ...$attributes],
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
            ->where('connection', $payload->connection)
            ->where('payment_id', $payload->paymentId)
            ->update(['processed_at' => now()]);
    }

    public function claimSubscription(SubscriptionWebhookPayload $payload): WebhookOutcome
    {
        if (! $this->enabled()) {
            return WebhookOutcome::Fresh;
        }

        $key = $this->subscriptionKey($payload);

        try {
            WlcmTransaction::query()->create([
                'connection' => $payload->connection,
                'api' => 'subscription',
                'external_id' => $payload->externalOrderId,
                'state_text' => $payload->event,
                'delivery_key' => $key,
            ]);

            return WebhookOutcome::Fresh;
        } catch (UniqueConstraintViolationException $e) {
            $existing = WlcmTransaction::query()
                ->where('connection', $payload->connection)
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
            ->where('connection', $payload->connection)
            ->where('delivery_key', $this->subscriptionKey($payload))
            ->update(['processed_at' => now()]);
    }

    private function subscriptionKey(SubscriptionWebhookPayload $payload): string
    {
        return hash('sha256', "{$payload->externalOrderId}:{$payload->event}:{$payload->timestamp}");
    }
}
