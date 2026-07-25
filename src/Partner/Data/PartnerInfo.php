<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

/**
 * @phpstan-type PartnerInfoShape array{id: WlcmJsonScalar, name: WlcmJsonScalar, company_name?: WlcmJsonScalar, receipt_item_name?: WlcmJsonScalar, email?: WlcmJsonScalar, phone?: WlcmJsonScalar, partner_type?: WlcmJsonScalar, status?: WlcmJsonScalar, is_active?: WlcmJsonScalar, created_at?: WlcmJsonScalar, sub_partners?: array<int, array<string, mixed>>, cancel_payment_enabled?: WlcmJsonScalar, show_phone_number?: WlcmJsonScalar}
 */
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
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $companyName = $data['company_name'] ?? null;
        $receiptItemName = $data['receipt_item_name'] ?? null;
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $partnerType = $data['partner_type'] ?? null;
        $status = $data['status'] ?? null;
        $createdAt = $data['created_at'] ?? null;
        $subPartners = $data['sub_partners'] ?? null;

        return new self(
            id: is_numeric($id) ? (int) $id : 0,
            name: is_scalar($name) ? (string) $name : '',
            companyName: is_scalar($companyName) ? (string) $companyName : null,
            receiptItemName: is_scalar($receiptItemName) ? (string) $receiptItemName : null,
            email: is_scalar($email) ? (string) $email : null,
            phone: is_scalar($phone) ? (string) $phone : null,
            partnerType: is_scalar($partnerType) ? (string) $partnerType : '',
            status: is_scalar($status) ? (string) $status : '',
            isActive: (bool) ($data['is_active'] ?? false),
            createdAt: is_scalar($createdAt) ? (string) $createdAt : null,
            subPartners: array_values(array_filter(is_array($subPartners) ? $subPartners : [], is_array(...))),
            cancelPaymentEnabled: (bool) ($data['cancel_payment_enabled'] ?? false),
            showPhoneNumber: (bool) ($data['show_phone_number'] ?? false),
            raw: $data,
        );
    }
}
