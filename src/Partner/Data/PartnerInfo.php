<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

final readonly class PartnerInfo
{
    /**
     * @param  list<array<string, mixed>>  $subPartners
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $companyName,
        public ?string $receiptItemName,
        public ?string $email,
        public ?string $phone,
        public string $partnerType,
        public string $status,
        public bool $isActive,
        public ?string $createdAt,
        public array $subPartners,
        public bool $cancelPaymentEnabled,
        public bool $showPhoneNumber,
        public array $raw,
    ) {}

    /** @param PartnerInfoShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            companyName: isset($data['company_name']) ? (string) $data['company_name'] : null,
            receiptItemName: isset($data['receipt_item_name']) ? (string) $data['receipt_item_name'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            partnerType: (string) ($data['partner_type'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            isActive: (bool) ($data['is_active'] ?? false),
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            subPartners: array_values((array) ($data['sub_partners'] ?? [])),
            cancelPaymentEnabled: (bool) ($data['cancel_payment_enabled'] ?? false),
            showPhoneNumber: (bool) ($data['show_phone_number'] ?? false),
            raw: $data,
        );
    }
}
