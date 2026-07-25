<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Support\Tiyin;

final readonly class WebhookPayload
{
    public readonly int $state;

    public function __construct(
        public string $externalId,
        public string $orderId,
        public string $paymentId,
        public string $amount,
        public string $rawState,
        public string $provider,
        public string $timestamp,
        public string $signature,
        public ?string $connection = null,
    ) {
        $this->state = (int) $rawState;
    }

    /** @param WebhookPayloadShape $data */
    public static function fromArray(array $data, ?string $connection = null): self
    {
        return new self(
            externalId: (string) ($data['external_id'] ?? ''),
            orderId: (string) ($data['order_id'] ?? ''),
            paymentId: (string) ($data['payment_id'] ?? ''),
            amount: (string) ($data['amount'] ?? '0'),
            rawState: (string) ($data['state'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            timestamp: (string) ($data['timestamp'] ?? ''),
            signature: (string) ($data['signature'] ?? ''),
            connection: $connection,
        );
    }

    public function signatureBase(): string
    {
        return "{$this->orderId}:{$this->paymentId}:{$this->rawState}:{$this->timestamp}";
    }

    public function stateEnum(): ?PaymentState
    {
        return PaymentState::tryFrom($this->state);
    }

    public function isSuccess(): bool
    {
        return $this->state === PaymentState::Success->value;
    }

    public function isCancelled(): bool
    {
        return $this->state === PaymentState::Cancelled->value;
    }

    // Webhook amount arrives as decimal SUM string; int tiyin without float drift.
    public function amountTiyin(): int
    {
        return Tiyin::fromSumString($this->amount);
    }
}
