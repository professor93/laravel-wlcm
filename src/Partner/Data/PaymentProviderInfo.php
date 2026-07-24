<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

final readonly class PaymentProviderInfo
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $name,
        public string $label,
        public int $sortOrder,
        public bool $isActive,
        public ?string $icon,
        public array $raw,
    ) {}

    /** @param PaymentProviderInfoShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            sortOrder: (int) ($data['sort_order'] ?? 0),
            isActive: (bool) ($data['is_active'] ?? false),
            icon: isset($data['icon']) ? (string) $data['icon'] : null,
            raw: $data,
        );
    }
}
