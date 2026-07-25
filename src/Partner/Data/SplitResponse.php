<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

/**
 * @phpstan-import-type SplitRecipientShape from SplitRecipient
 *
 * @phpstan-type SplitResponseShape array{payment_id: WlcmJsonScalar, split_type?: WlcmJsonScalar, total_recipients?: WlcmJsonScalar, total_split_amount?: int|float|string|null, splits?: array<int, SplitRecipientShape>}
 */
final readonly class SplitResponse
{
    /**
     * @param  list<SplitRecipient>  $splits
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $paymentId,
        public string $splitType,
        public int $totalRecipients,
        public int|float|string $totalSplitAmount,
        public array $splits,
        public array $raw,
    ) {}

    /** @param SplitResponseShape $data */
    public static function fromArray(array $data): self
    {
        $paymentId = $data['payment_id'] ?? null;
        $splitType = $data['split_type'] ?? null;
        $totalRecipients = $data['total_recipients'] ?? null;
        $totalSplitAmount = $data['total_split_amount'] ?? null;
        $splits = $data['splits'] ?? null;

        return new self(
            paymentId: is_numeric($paymentId) ? (int) $paymentId : 0,
            splitType: is_scalar($splitType) ? (string) $splitType : '',
            totalRecipients: is_numeric($totalRecipients) ? (int) $totalRecipients : 0,
            totalSplitAmount: is_int($totalSplitAmount) || is_float($totalSplitAmount) || is_string($totalSplitAmount) ? $totalSplitAmount : 0,
            splits: array_map(
                SplitRecipient::fromArray(...),
                array_values(array_filter(is_array($splits) ? $splits : [], is_array(...))),
            ),
            raw: $data,
        );
    }
}
