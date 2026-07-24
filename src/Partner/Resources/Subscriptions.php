<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Exceptions\ConnectionException;
use Uzbek\Wlcm\Partner\Data\Subscription;
use Uzbek\Wlcm\Partner\Data\SubscriptionCardAttached;
use Uzbek\Wlcm\Partner\Data\SubscriptionChargeResult;
use Uzbek\Wlcm\Partner\ErrorMapper;
use Uzbek\Wlcm\Partner\PartnerClient;
use Uzbek\Wlcm\Support\Tiyin;

final readonly class Subscriptions
{
    public function __construct(private PartnerClient $client) {}

    public function create(string $externalOrderId, string $externalUserId, int $amountTiyin, ?string $returnUrl = null): Subscription
    {
        $payload = array_filter([
            'amount' => Tiyin::toSum($amountTiyin),
            'external_order_id' => $externalOrderId,
            'external_user_id' => $externalUserId,
            'return_url' => $returnUrl,
        ], fn (mixed $v): bool => $v !== null);

        $result = $this->client->post('/subscriptions', $payload);
        /** @var SubscriptionShape $result */

        return Subscription::fromArray($result);
    }

    public function get(int $subscriptionId): Subscription
    {
        $result = $this->client->get("/subscriptions/{$subscriptionId}");
        /** @var SubscriptionShape $result */

        return Subscription::fromArray($result);
    }

    // Server quirk: lookup by external order id goes through sub id 0 plus query param.
    public function findByExternalOrderId(string $externalOrderId): Subscription
    {
        $result = $this->client->get('/subscriptions/0', ['external_order_id' => $externalOrderId]);
        /** @var SubscriptionShape $result */

        return Subscription::fromArray($result);
    }

    public function charge(int $subscriptionId, int $amountTiyin, int $cardId): SubscriptionChargeResult
    {
        $result = $this->client->post("/subscriptions/{$subscriptionId}/charge", [
            'amount' => Tiyin::toSum($amountTiyin),
            'card_id' => $cardId,
        ]);
        /** @var SubscriptionChargeResultShape $result */

        return SubscriptionChargeResult::fromArray($result);
    }

    // Live-verified: cancel returns the full subscription object with status=cancelled.
    public function cancel(int $subscriptionId): Subscription
    {
        $result = $this->client->post("/subscriptions/{$subscriptionId}/cancel");
        /** @var SubscriptionShape $result */

        return Subscription::fromArray($result);
    }

    // Card attach/confirm/activate are end-user calls: unsigned, no partner auth.
    public function attachCard(int $subscriptionId, string $cardNumber, string $expireDate, ?string $phoneNumber = null): SubscriptionCardAttached
    {
        $payload = array_filter([
            'card_number' => $cardNumber,
            'expire_date' => $expireDate,
            'phone_number' => $phoneNumber,
        ], fn (mixed $v): bool => $v !== null);

        $result = $this->userPost("/subscriptions/{$subscriptionId}/cards", $payload);
        /** @var SubscriptionCardAttachedShape $result */

        return SubscriptionCardAttached::fromArray($result);
    }

    /** @return array<string, mixed> */
    public function confirmCard(int $subscriptionId, string $otp, ?int $cardId = null): array
    {
        $payload = array_filter([
            'card_id' => $cardId,
            'otp' => $otp,
        ], fn (mixed $v): bool => $v !== null);

        return $this->userPost("/subscriptions/{$subscriptionId}/cards/confirm", $payload);
    }

    /** @return array<string, mixed> */
    public function activate(int $subscriptionId): array
    {
        return $this->userPost("/subscriptions/{$subscriptionId}/activate", []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function userPost(string $path, array $payload): array
    {
        $url = $this->client->baseUrl().'/'.ltrim($path, '/');
        $http = $this->client->httpConfig();

        try {
            $response = Http::timeout((int) ($http['timeout'] ?? 15))
                ->connectTimeout((int) ($http['connect_timeout'] ?? 5))
                ->acceptJson()
                ->post($url, $payload);
        } catch (HttpConnectionException $e) {
            throw new ConnectionException($e->getMessage(), $this->client->connectionName, 'POST', $url, previous: $e);
        }

        return ErrorMapper::handle($response, $this->client->connectionName, 'POST', $url);
    }
}
