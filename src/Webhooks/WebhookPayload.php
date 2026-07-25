<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Support\Tiyin;

/**
 * @phpstan-type WebhookPayloadShape array{external_id?: WlcmJsonScalar, order_id?: WlcmJsonScalar, payment_id?: WlcmJsonScalar, amount?: WlcmJsonScalar, state?: WlcmJsonScalar, provider?: WlcmJsonScalar, timestamp?: WlcmJsonScalar, signature?: WlcmJsonScalar}
 */
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
        $externalId = $data['external_id'] ?? null;
        $provider = $data['provider'] ?? null;

        return new self(
            externalId: is_scalar($externalId) ? (string) $externalId : '',
            orderId: (string) ($data['order_id'] ?? ''),
            paymentId: (string) ($data['payment_id'] ?? ''),
            amount: (string) ($data['amount'] ?? '0'),
            rawState: (string) ($data['state'] ?? ''),
            provider: is_scalar($provider) ? (string) $provider : '',
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
