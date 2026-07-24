<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

final readonly class SplitResponse
{
    /** @param list<SplitRecipient> $splits */
    public function __construct(
        public int $paymentId,
        public string $splitType,
        public int $totalRecipients,
        public int|float|string $totalSplitAmount,
        public array $splits,
    ) {}

    /** @param SplitResponseShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            paymentId: (int) $data['payment_id'],
            splitType: (string) ($data['split_type'] ?? ''),
            totalRecipients: (int) ($data['total_recipients'] ?? 0),
            totalSplitAmount: $data['total_split_amount'] ?? 0,
            splits: array_map(SplitRecipient::fromArray(...), array_values((array) ($data['splits'] ?? []))),
        );
    }
}
