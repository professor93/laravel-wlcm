<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Enums\PaymentState;

final readonly class CheckoutResponse
{
    public function __construct(
        public int $orderId,
        public ?string $externalId,
        public ?int $state,
        public ?string $checkoutUrl,
        public ?string $message,
        public ?string $transactionId = null,
        public ?string $otpSentPhone = null,
        public ?string $cid = null,
    ) {}

    /** @param CheckoutResponseShape $data */
    public static function fromArray(array $data): self
    {
        $orderId = $data['order_id'] ?? null;
        $stateRaw = $data['state'] ?? null;
        $externalId = $data['external_id'] ?? null;
        $checkoutUrl = $data['checkout_url'] ?? null;
        $message = $data['message'] ?? null;
        $transactionId = $data['transaction_id'] ?? null;
        $otpSentPhone = $data['otp_sent_phone'] ?? null;
        $cid = $data['cid'] ?? null;

        return new self(
            orderId: is_numeric($orderId) ? (int) $orderId : 0,
            externalId: is_scalar($externalId) ? (string) $externalId : null,
            state: is_numeric($stateRaw) ? (int) $stateRaw : null,
            checkoutUrl: is_scalar($checkoutUrl) ? (string) $checkoutUrl : null,
            message: is_scalar($message) ? (string) $message : null,
            transactionId: is_scalar($transactionId) ? (string) $transactionId : null,
            otpSentPhone: is_scalar($otpSentPhone) ? (string) $otpSentPhone : null,
            cid: is_scalar($cid) ? (string) $cid : null,
        );
    }

    public function stateEnum(): ?PaymentState
    {
        return $this->state === null ? null : PaymentState::tryFrom($this->state);
    }
}
