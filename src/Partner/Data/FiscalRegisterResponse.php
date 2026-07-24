<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

final readonly class FiscalRegisterResponse
{
    public function __construct(
        public string $message,
        public int $fiscalId,
        public string $fiscalNumber,
        public string $fiscalSign,
        public string $qrCodeUrl,
        public bool $isRefund,
        public ?string $printedAt,
    ) {}

    /** @param FiscalRegisterResponseShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            message: (string) ($data['message'] ?? ''),
            fiscalId: (int) ($data['fiscal_id'] ?? 0),
            fiscalNumber: (string) ($data['fiscal_number'] ?? ''),
            fiscalSign: (string) ($data['fiscal_sign'] ?? ''),
            qrCodeUrl: (string) ($data['qr_code_url'] ?? ''),
            isRefund: (bool) ($data['is_refund'] ?? false),
            printedAt: isset($data['printed_at']) ? (string) $data['printed_at'] : null,
        );
    }
}
