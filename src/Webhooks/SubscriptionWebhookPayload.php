<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Uzbek\Wlcm\Support\Tiyin;

/**
 * @phpstan-type SubscriptionWebhookPayloadShape array{event?: WlcmJsonScalar, external_order_id?: WlcmJsonScalar, external_user_id?: WlcmJsonScalar, subscription_id?: WlcmJsonScalar, amount?: WlcmJsonScalar, status?: WlcmJsonScalar, timestamp?: WlcmJsonScalar, signature?: WlcmJsonScalar}
 */
final readonly class SubscriptionWebhookPayload
{
    public function __construct(
        public string $event,
        public string $externalOrderId,
        public string $externalUserId,
        public int $subscriptionId,
        public string $amount,
        public string $status,
        public string $timestamp,
        public string $signature,
        public ?string $connection = null,
    ) {}

    /** @param SubscriptionWebhookPayloadShape $data */
    public static function fromArray(array $data, ?string $connection = null): self
    {
        $externalUserId = $data['external_user_id'] ?? null;
        $subscriptionId = $data['subscription_id'] ?? null;
        $amount = $data['amount'] ?? null;
        $status = $data['status'] ?? null;

        return new self(
            event: (string) ($data['event'] ?? ''),
            externalOrderId: (string) ($data['external_order_id'] ?? ''),
            externalUserId: is_scalar($externalUserId) ? (string) $externalUserId : '',
            subscriptionId: is_numeric($subscriptionId) ? (int) $subscriptionId : 0,
            amount: is_scalar($amount) ? (string) $amount : '0',
            status: is_scalar($status) ? (string) $status : '',
            timestamp: (string) ($data['timestamp'] ?? ''),
            signature: (string) ($data['signature'] ?? ''),
            connection: $connection,
        );
    }

    public function signatureBase(): string
    {
        return "{$this->externalOrderId}:{$this->event}:{$this->timestamp}";
    }

    // Subscription webhook amount is a decimal SUM string.
    public function amountTiyin(): int
    {
        return Tiyin::fromSumString($this->amount);
    }
}
