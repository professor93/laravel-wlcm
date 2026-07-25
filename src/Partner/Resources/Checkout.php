<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Uzbek\Wlcm\Enums\PaymentProvider;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Partner\PartnerClient;

final readonly class Checkout
{
    public function __construct(private PartnerClient $client) {}

    public function create(
        int $amountTiyin,
        ?string $returnUrl = null,
        ?string $externalId = null,
        PaymentProvider|string|null $provider = null,
        ?string $cardNumber = null,
        ?string $expireDate = null,
    ): CheckoutResponse {
        if ($amountTiyin <= 0) {
            throw new InvalidAmountException("Checkout amount must be positive tiyin, got {$amountTiyin}");
        }

        $payload = array_filter([
            'amount' => $amountTiyin,
            'external_id' => $externalId,
            'payment_provider' => $provider instanceof PaymentProvider ? $provider->value : $provider,
            'card_number' => $cardNumber,
            'expire_date' => $expireDate,
            'return_url' => $returnUrl,
        ], fn (mixed $v): bool => $v !== null);

        ksort($payload);

        $checkoutResult = $this->client->post('/integrations/checkout', $payload);
        /** @var CheckoutResponseShape $checkoutResult */
        $response = CheckoutResponse::fromArray($checkoutResult);

        $this->client->transactions()->recordCheckout($this->client->connectionName, $payload, $response);

        return $response;
    }
}
