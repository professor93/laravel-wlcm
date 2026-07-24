<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

final readonly class FiscalReceipt
{
    public function __construct(
        public int $id,
        public int $paymentId,
        public int $orderId,
        public string $fiscalSign,
        public string $fiscalNumber,
        public string $qrCodeUrl,
        public string $receiptType,
        public bool $isRefund,
        public ?string $printedAt,
    ) {}

    /** @param FiscalReceiptShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            paymentId: (int) ($data['payment_id'] ?? 0),
            orderId: (int) ($data['order_id'] ?? 0),
            fiscalSign: (string) ($data['fiscal_sign'] ?? ''),
            fiscalNumber: (string) ($data['fiscal_number'] ?? ''),
            qrCodeUrl: (string) ($data['qr_code_url'] ?? ''),
            receiptType: (string) ($data['receipt_type'] ?? ''),
            isRefund: (bool) ($data['is_refund'] ?? false),
            printedAt: isset($data['printed_at']) ? (string) $data['printed_at'] : null,
        );
    }
}
