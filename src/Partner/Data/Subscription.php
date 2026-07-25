<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

use Uzbek\Wlcm\Support\Tiyin;

/**
 * @phpstan-type SubscriptionShape array{id?: WlcmJsonScalar, partner_id?: WlcmJsonScalar, external_order_id?: WlcmJsonScalar, external_user_id?: WlcmJsonScalar, amount?: WlcmJsonScalar, status?: WlcmJsonScalar, payment_id?: WlcmJsonScalar, last_payment_id?: WlcmJsonScalar, card_number_masked?: WlcmJsonScalar, card_vendor?: WlcmJsonScalar, last_payment_status?: WlcmJsonScalar, last_payment_amount?: WlcmJsonScalar, last_payment_at?: WlcmJsonScalar, last_payment_transaction_id?: WlcmJsonScalar, last_fiscal_number?: WlcmJsonScalar, return_url?: WlcmJsonScalar, partner_logo_url?: WlcmJsonScalar, created_at?: WlcmJsonScalar}
 */
final readonly class Subscription
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public int $id,
        public int $partnerId,
        public string $externalOrderId,
        public string $externalUserId,
        public string $amount,
        public string $status,
        public ?int $paymentId,
        public ?int $lastPaymentId,
        public ?string $cardNumberMasked,
        public ?string $cardVendor,
        public ?string $lastPaymentStatus,
        public ?string $lastPaymentAmount,
        public ?string $lastPaymentAt,
        public ?string $lastPaymentTransactionId,
        public ?string $lastFiscalNumber,
        public ?string $returnUrl,
        public ?string $partnerLogoUrl,
        public ?string $createdAt,
        public array $raw,
    ) {}

    /** @param SubscriptionShape $data */
    public static function fromArray(array $data): self
    {
        $paymentId = $data['payment_id'] ?? null;
        $lastPaymentId = $data['last_payment_id'] ?? null;

        return new self(
            id: (int) ($data['id'] ?? 0),
            partnerId: (int) ($data['partner_id'] ?? 0),
            externalOrderId: (string) ($data['external_order_id'] ?? ''),
            externalUserId: (string) ($data['external_user_id'] ?? ''),
            amount: (string) ($data['amount'] ?? '0'),
            status: (string) ($data['status'] ?? ''),
            paymentId: is_numeric($paymentId) ? (int) $paymentId : null,
            lastPaymentId: is_numeric($lastPaymentId) ? (int) $lastPaymentId : null,
            cardNumberMasked: isset($data['card_number_masked']) ? (string) $data['card_number_masked'] : null,
            cardVendor: isset($data['card_vendor']) ? (string) $data['card_vendor'] : null,
            lastPaymentStatus: isset($data['last_payment_status']) ? (string) $data['last_payment_status'] : null,
            lastPaymentAmount: isset($data['last_payment_amount']) ? (string) $data['last_payment_amount'] : null,
            lastPaymentAt: isset($data['last_payment_at']) ? (string) $data['last_payment_at'] : null,
            lastPaymentTransactionId: isset($data['last_payment_transaction_id']) ? (string) $data['last_payment_transaction_id'] : null,
            lastFiscalNumber: isset($data['last_fiscal_number']) ? (string) $data['last_fiscal_number'] : null,
            returnUrl: isset($data['return_url']) ? (string) $data['return_url'] : null,
            partnerLogoUrl: isset($data['partner_logo_url']) ? (string) $data['partner_logo_url'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            raw: $data,
        );
    }

    public function amountTiyin(): int
    {
        return Tiyin::fromSumString($this->amount);
    }
}
