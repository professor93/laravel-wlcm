<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

final readonly class WebhookInfo
{
    /**
     * @param  list<mixed>|null  $events
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $id,
        public string $url,
        public ?string $secretKey,
        public ?bool $isActive,
        public ?array $events,
        public ?string $lastTriggeredAt,
        public ?int $lastStatusCode,
        public ?string $createdAt,
        public array $raw,
    ) {}

    /** @param WebhookInfoShape $data */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $url = $data['url'] ?? null;
        $secretKey = $data['secret_key'] ?? null;
        $events = $data['events'] ?? null;
        $lastTriggeredAt = $data['last_triggered_at'] ?? null;
        $lastStatusCode = $data['last_status_code'] ?? null;
        $createdAt = $data['created_at'] ?? null;

        return new self(
            id: is_numeric($id) ? (int) $id : 0,
            url: is_scalar($url) ? (string) $url : '',
            secretKey: is_scalar($secretKey) ? (string) $secretKey : null,
            isActive: isset($data['is_active']) ? (bool) $data['is_active'] : null,
            events: is_array($events) ? array_values($events) : null,
            lastTriggeredAt: is_scalar($lastTriggeredAt) ? (string) $lastTriggeredAt : null,
            lastStatusCode: is_numeric($lastStatusCode) ? (int) $lastStatusCode : null,
            createdAt: is_scalar($createdAt) ? (string) $createdAt : null,
            raw: $data,
        );
    }
}
