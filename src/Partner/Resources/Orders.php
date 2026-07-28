<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Illuminate\Contracts\Config\Repository;
use Uzbek\Wlcm\Exceptions\CallerTransactionException;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Partner\Data\Order;
use Uzbek\Wlcm\Partner\Data\OrderStatus;
use Uzbek\Wlcm\Partner\PartnerClient;
use Uzbek\Wlcm\Support\CallerTransaction;

/**
 * @phpstan-import-type OrderStatusShape from OrderStatus
 * @phpstan-import-type OrderShape from Order
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

    public function create(int $amountTiyin, ?string $externalId = null): Order
    {
        if ($amountTiyin <= 0) {
            throw new InvalidAmountException("Order amount must be positive tiyin, got {$amountTiyin}");
        }

        $payload = array_filter([
            'amount' => $amountTiyin,
            'external_id' => $externalId,
        ], fn (mixed $v): bool => $v !== null);

        ksort($payload);

        // BEFORE the post, for the reason Checkout::create records: recordOrder runs
        // below, AFTER the call has created a real order at WLCM, and refusing there
        // would throw on an order we had just failed to record.
        if (CallerTransaction::open(app(Repository::class))) {
            throw CallerTransactionException::order();
        }

        $result = $this->client->post('/integrations/orders', $payload);
        /** @var OrderShape $result */
        $order = Order::fromArray($result);

        $this->client->transactions()->recordOrder($this->client->connectionName, $payload, $order);

        return $order;
    }
}
