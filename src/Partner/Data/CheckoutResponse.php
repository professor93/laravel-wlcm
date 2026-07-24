<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Enums\PaymentState;

final readonly class CheckoutResponse
{
    public function __construct(
        public int $orderId,
        public ?string $externalId,
        public int $state,
        public ?string $checkoutUrl,
        public ?string $message,
        public ?string $transactionId = null,
        public ?string $otpSentPhone = null,
        public ?string $cid = null,
    ) {}

    /** @param CheckoutResponseShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            orderId: (int) $data['order_id'],
            externalId: isset($data['external_id']) ? (string) $data['external_id'] : null,
            state: (int) ($data['state'] ?? 0),
            checkoutUrl: isset($data['checkout_url']) ? (string) $data['checkout_url'] : null,
            message: isset($data['message']) ? (string) $data['message'] : null,
            transactionId: isset($data['transaction_id']) ? (string) $data['transaction_id'] : null,
            otpSentPhone: isset($data['otp_sent_phone']) ? (string) $data['otp_sent_phone'] : null,
            cid: isset($data['cid']) ? (string) $data['cid'] : null,
        );
    }

    public function stateEnum(): ?PaymentState
    {
        return PaymentState::tryFrom($this->state);
    }
}
