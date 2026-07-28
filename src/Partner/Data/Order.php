<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Enums\OrderState;

/**
 * @phpstan-type OrderShape array{order_id: WlcmJsonScalar, external_id?: WlcmJsonScalar, amount?: WlcmJsonScalar, status?: WlcmJsonScalar}
 */
final readonly class Order
{
    public function __construct(
        public int $orderId,
        public ?string $externalId,
        public ?int $amountTiyin,
        public ?string $status,
    ) {}

    /** @param OrderShape $data */
    public static function fromArray(array $data): self
    {
        $orderId = $data['order_id'] ?? null;
        $externalId = $data['external_id'] ?? null;
        $amount = $data['amount'] ?? null;
        $status = $data['status'] ?? null;

        return new self(
            orderId: is_numeric($orderId) ? (int) $orderId : 0,
            externalId: is_scalar($externalId) ? (string) $externalId : null,
            amountTiyin: is_numeric($amount) ? (int) $amount : null,
            status: is_scalar($status) ? (string) $status : null,
        );
    }

    // status is a string here, not the integer state every other endpoint returns.
    public function statusEnum(): ?OrderState
    {
        return $this->status === null ? null : OrderState::tryFrom($this->status);
    }
}
