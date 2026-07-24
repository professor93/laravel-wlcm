<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Uzbek\Wlcm\Support\Tiyin;

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
        return new self(
            event: (string) ($data['event'] ?? ''),
            externalOrderId: (string) ($data['external_order_id'] ?? ''),
            externalUserId: (string) ($data['external_user_id'] ?? ''),
            subscriptionId: (int) ($data['subscription_id'] ?? 0),
            amount: (string) ($data['amount'] ?? '0'),
            status: (string) ($data['status'] ?? ''),
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
