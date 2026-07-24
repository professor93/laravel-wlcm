<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

final readonly class SplitRecipient
{
    public function __construct(
        public int $id,
        public string $recipientType,
        public int $recipientId,
        public string $splitType,
        // Live servers report split amounts as decimal SUM strings; kept raw.
        public int|float|string $amount,
        public int|float|string|null $percentValue,
        public int $state,
        public string $stateText,
    ) {}

    /** @param SplitRecipientShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            recipientType: (string) $data['recipient_type'],
            recipientId: (int) $data['recipient_id'],
            splitType: (string) $data['split_type'],
            amount: $data['amount'],
            percentValue: $data['percent_value'] ?? null,
            state: (int) ($data['state'] ?? 0),
            stateText: (string) ($data['state_text'] ?? ''),
        );
    }
}
