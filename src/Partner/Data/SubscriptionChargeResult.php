<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Enums\WlcmErrorCode;

// Charge returns HTTP 200 for both outcomes; a failed charge is data, not an exception.
final readonly class SubscriptionChargeResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $status,
        public ?int $paymentId,
        public ?string $message,
        public array $raw,
    ) {}

    /** @param SubscriptionChargeResultShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status: (string) ($data['status'] ?? ''),
            paymentId: isset($data['payment_id']) ? (int) $data['payment_id'] : null,
            message: isset($data['message']) ? (string) $data['message'] : null,
            raw: $data,
        );
    }

    // Guard every charge with this: no exception does NOT mean charged.
    public function succeeded(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function errorCodeEnum(): ?WlcmErrorCode
    {
        return $this->message === null ? null : WlcmErrorCode::tryFrom($this->message);
    }
}
