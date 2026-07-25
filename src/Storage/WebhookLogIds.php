<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

final class WebhookLogIds
{
    /**
     * Family split, same rule the middleware uses: only subscriptions carry `event`.
     * Their merchant reference is external_order_id, filed under external_id to
     * match the wlcm_transactions claim row; subscription_id is WLCM's own
     * namespace and would collide with a payment id in the same column.
     *
     * @param  array<string, mixed>  $payload
     * @return array{external_id: string|null, order_id: string|null, payment_id: string|null}
     */
    public static function from(array $payload): array
    {
        $externalId = isset($payload['event'])
            ? $payload['external_order_id'] ?? null
            : $payload['external_id'] ?? null;

        $orderId = $payload['order_id'] ?? null;
        $paymentId = $payload['payment_id'] ?? null;

        return [
            'external_id' => is_scalar($externalId) ? (string) $externalId : null,
            'order_id' => is_scalar($orderId) ? (string) $orderId : null,
            'payment_id' => is_scalar($paymentId) ? (string) $paymentId : null,
        ];
    }
}
