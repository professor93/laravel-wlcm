<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Illuminate\Support\Facades\DB;
use Throwable;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class TransactionRecorder
{
    private static function enabled(): bool
    {
        return (bool) config('wlcm.storage.transactions', false);
    }

    /** @param array<string, mixed> $requestPayload */
    public static function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void
    {
        if (! self::enabled()) {
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

    // False = duplicate delivery: row already holds this payment state.
    // Transaction + lockForUpdate closes the concurrent-delivery race; order_id
    // reconciliation claims the checkout-created row instead of orphaning it.
    public static function applyWebhook(WebhookPayload $payload): bool
    {
        if (! self::enabled()) {
            return true;
        }

        return DB::transaction(function () use ($payload): bool {
            $duplicate = WlcmTransaction::query()
                ->where('connection', $payload->connection)
                ->where('payment_id', $payload->paymentId)
                ->where('state', $payload->state)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                return false;
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
            ];

            $checkoutRow = WlcmTransaction::query()
                ->where('connection', $payload->connection)
                ->where('order_id', $payload->orderId)
                ->whereNull('payment_id')
                ->lockForUpdate()
                ->first();

            if ($checkoutRow !== null) {
                $checkoutRow->update($attributes);

                return true;
            }

            WlcmTransaction::query()->updateOrCreate(
                ['connection' => $payload->connection, 'payment_id' => $payload->paymentId],
                $attributes,
            );

            return true;
        });
    }
}
