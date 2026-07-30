<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Illuminate\Contracts\Config\Repository;
use Uzbek\Wlcm\Enums\PaymentProvider;
use Uzbek\Wlcm\Exceptions\CallerTransactionException;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Exceptions\InvalidOrderReferenceException;
use Uzbek\Wlcm\Partner\Data\Order;
use Uzbek\Wlcm\Partner\Data\OrderLink;
use Uzbek\Wlcm\Partner\Data\OrderStatus;
use Uzbek\Wlcm\Partner\PartnerClient;
use Uzbek\Wlcm\Support\CallerTransaction;

/**
 * @phpstan-import-type OrderStatusShape from OrderStatus
 * @phpstan-import-type OrderShape from Order
 * @phpstan-import-type OrderLinkShape from OrderLink
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

    /**
     * Attaches a provider to an existing order and returns its checkout URL.
     *
     * Repeatable: calling again with a different provider switches it, server-side last
     * call wins. The URL depends on the provider as well as the order — click answers
     * /pay/click/{id} where payme answers /pay/{id} — so never reuse a URL across a
     * switch. Always redirect to the one the latest call returned.
     */
    public function generateLink(
        PaymentProvider|string $provider,
        string $returnUrl,
        ?int $orderId = null,
        ?string $externalId = null,
    ): OrderLink {
        // The server enforces the same XOR and answers 422. Refusing here saves a round
        // trip on a payload we can already see is wrong.
        if (($orderId === null) === ($externalId === null)) {
            throw InvalidOrderReferenceException::notExactlyOne();
        }

        $providerName = $provider instanceof PaymentProvider ? $provider->value : $provider;

        $payload = array_filter([
            'order_id' => $orderId,
            'external_id' => $externalId,
            'payment_provider' => $providerName,
            // Passed through verbatim. The server accepts an empty string with 200 and
            // the package does not refuse what the platform allows.
            'return_url' => $returnUrl,
        ], fn (mixed $v): bool => $v !== null);

        ksort($payload);

        // BEFORE the post, same reason as create(): the row update below runs after WLCM
        // already holds the provider choice, so refusing afterwards would leave the audit
        // row disagreeing with the platform.
        if (CallerTransaction::open(app(Repository::class))) {
            throw CallerTransactionException::order();
        }

        $result = $this->client->post('/integrations/generate/link', $payload);
        /** @var OrderLinkShape $result */
        $link = OrderLink::fromArray($result);

        $this->client->transactions()->recordOrderLink($this->client->connectionName, $providerName, $link);

        return $link;
    }
}
