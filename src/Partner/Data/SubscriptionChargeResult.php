<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Enums\WlcmErrorCode;

// Charge returns HTTP 200 for both outcomes; a failed charge is data, not an exception.
/**
 * @phpstan-type SubscriptionChargeResultShape array{status?: WlcmJsonScalar, payment_id?: WlcmJsonScalar, message?: WlcmJsonScalar}
 */
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
        $status = $data['status'] ?? null;
        $message = $data['message'] ?? null;

        return new self(
            status: is_scalar($status) ? (string) $status : '',
            paymentId: isset($data['payment_id']) ? (int) $data['payment_id'] : null,
            message: is_scalar($message) ? (string) $message : null,
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
        return ! $this->succeeded() && $this->message !== null ? WlcmErrorCode::tryFrom($this->message) : null;
    }
}
