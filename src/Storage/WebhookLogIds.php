<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Uzbek\Wlcm\Webhooks\WebhookFamily;

final class WebhookLogIds
{
    /**
     * Family split via WebhookFamily — literally the same predicate the middleware calls,
     * not a second one that happens to agree: only subscriptions carry `event`.
     * Their merchant reference is external_order_id, filed under external_id to
     * match the wlcm_transactions claim row; subscription_id is WLCM's own
     * namespace and would collide with a payment id in the same column.
     *
     * Feed this the RAW delivery. The merchant reference is scrubbed here because only
     * it can hide a pan; order_id/payment_id are WLCM's own and stay byte-intact, so the
     * log still joins wlcm_transactions on them.
     *
     * @param  array<string, mixed>  $payload
     * @return array{external_id: string|null, order_id: string|null, payment_id: string|null}
     */
    public static function from(array $payload): array
    {
        $externalId = WebhookFamily::isSubscription($payload)
            ? $payload['external_order_id'] ?? null
            : $payload['external_id'] ?? null;

        $orderId = $payload['order_id'] ?? null;
        $paymentId = $payload['payment_id'] ?? null;

        return [
            'external_id' => is_scalar($externalId) ? Masker::scrubPans((string) $externalId) : null,
            'order_id' => is_scalar($orderId) ? (string) $orderId : null,
            'payment_id' => is_scalar($paymentId) ? (string) $paymentId : null,
        ];
    }
}
