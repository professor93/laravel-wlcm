<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Enums\WlcmErrorCode;

// Charge returns HTTP 200 both outcomes; failed charge is data, not exception.
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
        $paymentId = $data['payment_id'] ?? null;
        $message = $data['message'] ?? null;

        return new self(
            status: is_scalar($status) ? (string) $status : '',
            paymentId: is_numeric($paymentId) ? (int) $paymentId : null,
            message: is_scalar($message) ? (string) $message : null,
            raw: $data,
        );
    }

    // Guard every charge: no exception does NOT mean charged.
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
