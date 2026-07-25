<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Uzbek\Wlcm\Partner\Data\OrderStatus;
use Uzbek\Wlcm\Partner\PartnerClient;

/**
 * @phpstan-import-type OrderStatusShape from OrderStatus
 */
final readonly class Orders
{
    public function __construct(private PartnerClient $client) {}

    public function status(int $orderId): OrderStatus
    {
        $result = $this->client->get("/orders/{$orderId}/status");
        /** @var OrderStatusShape $result */

        return OrderStatus::fromArray($result);
    }
}
