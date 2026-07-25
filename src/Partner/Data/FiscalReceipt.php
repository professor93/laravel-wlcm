<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

/**
 * @phpstan-type FiscalReceiptShape array{id: WlcmJsonScalar, payment_id?: WlcmJsonScalar, order_id?: WlcmJsonScalar, fiscal_sign?: WlcmJsonScalar, fiscal_number?: WlcmJsonScalar, qr_code_url?: WlcmJsonScalar, receipt_type?: WlcmJsonScalar, is_refund?: WlcmJsonScalar, printed_at?: WlcmJsonScalar}
 */
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
        $id = $data['id'] ?? null;
        $paymentId = $data['payment_id'] ?? null;
        $orderId = $data['order_id'] ?? null;
        $fiscalSign = $data['fiscal_sign'] ?? null;
        $fiscalNumber = $data['fiscal_number'] ?? null;
        $qrCodeUrl = $data['qr_code_url'] ?? null;
        $receiptType = $data['receipt_type'] ?? null;
        $printedAt = $data['printed_at'] ?? null;

        return new self(
            id: is_numeric($id) ? (int) $id : 0,
            paymentId: is_numeric($paymentId) ? (int) $paymentId : 0,
            orderId: is_numeric($orderId) ? (int) $orderId : 0,
            fiscalSign: is_scalar($fiscalSign) ? (string) $fiscalSign : '',
            fiscalNumber: is_scalar($fiscalNumber) ? (string) $fiscalNumber : '',
            qrCodeUrl: is_scalar($qrCodeUrl) ? (string) $qrCodeUrl : '',
            receiptType: is_scalar($receiptType) ? (string) $receiptType : '',
            isRefund: (bool) ($data['is_refund'] ?? false),
            printedAt: is_scalar($printedAt) ? (string) $printedAt : null,
        );
    }
}
