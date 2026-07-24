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
        return new self(
            id: (int) ($data['id'] ?? 0),
            url: (string) ($data['url'] ?? ''),
            secretKey: isset($data['secret_key']) ? (string) $data['secret_key'] : null,
            isActive: isset($data['is_active']) ? (bool) $data['is_active'] : null,
            events: isset($data['events']) ? array_values($data['events']) : null,
            lastTriggeredAt: isset($data['last_triggered_at']) ? (string) $data['last_triggered_at'] : null,
            lastStatusCode: isset($data['last_status_code']) ? (int) $data['last_status_code'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            raw: $data,
        );
    }
}
