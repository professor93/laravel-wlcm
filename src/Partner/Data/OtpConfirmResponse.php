<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

/**
 * @phpstan-type OtpConfirmResponseShape array{success?: WlcmJsonScalar, order_id?: WlcmJsonScalar, message?: WlcmJsonScalar}
 */
final readonly class OtpConfirmResponse
{
    public function __construct(
        public bool $success,
        public int $orderId,
        public ?string $message,
    ) {}

    /** @param OtpConfirmResponseShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? false),
            orderId: (int) ($data['order_id'] ?? 0),
            message: isset($data['message']) ? (string) $data['message'] : null,
        );
    }

    // Exact server prose; breaks silently if WLCM rewords. No machine code exists for this case.
    public function alreadyPaid(): bool
    {
        return $this->message === 'Already paid';
    }
}
