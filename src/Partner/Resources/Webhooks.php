<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Resources;

use Uzbek\Wlcm\Partner\Data\WebhookInfo;
use Uzbek\Wlcm\Partner\PartnerClient;

final readonly class Webhooks
{
    public function __construct(private PartnerClient $client) {}

    /** @return list<WebhookInfo> */
    public function all(): array
    {
        $result = $this->client->get('/partners/me/webhooks');

        $rows = is_array($result['results'] ?? null) ? $result['results'] : $result;
        $items = array_values(array_filter($rows, is_array(...)));
        /** @var list<WebhookInfoShape> $items */

        return array_map(WebhookInfo::fromArray(...), $items);
    }

    public function create(string $url, ?string $secretKey = null): WebhookInfo
    {
        $payload = array_filter([
            'secret_key' => $secretKey,
            'url' => $url,
        ], fn (mixed $v): bool => $v !== null);

        $result = $this->client->post('/partners/me/webhooks', $payload);
        /** @var WebhookInfoShape $result */

        return WebhookInfo::fromArray($result);
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $webhookId, array $attributes): WebhookInfo
    {
        $result = $this->client->patch("/partners/me/webhooks/{$webhookId}", $attributes);
        /** @var WebhookInfoShape $result */

        return WebhookInfo::fromArray($result);
    }

    /**
     * Raw passthrough: DELETE acks with no documented body, unlike PATCH which returns the object.
     *
     * @return array<string, mixed>
     */
    public function delete(int $webhookId): array
    {
        return $this->client->delete("/partners/me/webhooks/{$webhookId}");
    }

    /** @return list<array<string, mixed>> */
    public function events(int $webhookId): array
    {
        $result = $this->client->get("/partners/me/webhooks/{$webhookId}/events");

        // Live history is paginated {count,next,previous,results:[...]}; tolerate a bare list.
        $rows = is_array($result['results'] ?? null) ? $result['results'] : $result;
        $items = array_values(array_filter($rows, is_array(...)));
        /** @var list<array<string, mixed>> $items */

        return $items;
    }
}
