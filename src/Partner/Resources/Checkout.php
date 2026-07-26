<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Illuminate\Contracts\Config\Repository;
use Uzbek\Wlcm\Enums\PaymentProvider;
use Uzbek\Wlcm\Exceptions\CallerTransactionException;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Partner\PartnerClient;
use Uzbek\Wlcm\Support\CallerTransaction;

/**
 * @phpstan-import-type CheckoutResponseShape from CheckoutResponse
 */
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

        // BEFORE the post, not beside the row it protects. recordCheckout runs below,
        // AFTER the call has already created a real payment at WLCM — refusing there
        // would add a throw to a payment we had just failed to record, which is strictly
        // worse than doing nothing at all.
        if (CallerTransaction::open(app(Repository::class))) {
            throw CallerTransactionException::checkout();
        }

        $checkoutResult = $this->client->post('/integrations/checkout', $payload);
        /** @var CheckoutResponseShape $checkoutResult */
        $response = CheckoutResponse::fromArray($checkoutResult);

        $this->client->transactions()->recordCheckout($this->client->connectionName, $payload, $response);

        return $response;
    }
}
