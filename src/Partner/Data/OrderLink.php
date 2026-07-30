<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

/**
 * @phpstan-type OrderLinkShape array{order_id: WlcmJsonScalar, external_id?: WlcmJsonScalar, checkout_url?: WlcmJsonScalar}
 */
final readonly class OrderLink
{
    public function __construct(
        public int $orderId,
        public ?string $externalId,
        // Varies by PROVIDER as well as order — click answers /pay/click/{id} where
        // payme answers /pay/{id}. Never reuse one across a provider switch.
        public ?string $checkoutUrl,
    ) {}

    /** @param OrderLinkShape $data */
    public static function fromArray(array $data): self
    {
        $orderId = $data['order_id'] ?? null;
        $externalId = $data['external_id'] ?? null;
        $checkoutUrl = $data['checkout_url'] ?? null;

        return new self(
            orderId: is_numeric($orderId) ? (int) $orderId : 0,
            externalId: is_scalar($externalId) ? (string) $externalId : null,
            checkoutUrl: is_scalar($checkoutUrl) ? (string) $checkoutUrl : null,
        );
    }
}
