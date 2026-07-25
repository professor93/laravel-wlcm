<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

/**
 * @phpstan-type SplitRecipientShape array{id: WlcmJsonScalar, recipient_type: WlcmJsonScalar, recipient_id: WlcmJsonScalar, split_type: WlcmJsonScalar, amount?: int|float|string|null, percent_value?: int|float|string|null, state?: WlcmJsonScalar, state_text?: WlcmJsonScalar}
 */
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
        $id = $data['id'] ?? null;
        $recipientType = $data['recipient_type'] ?? null;
        $recipientId = $data['recipient_id'] ?? null;
        $splitType = $data['split_type'] ?? null;
        $amount = $data['amount'] ?? null;
        $percentValue = $data['percent_value'] ?? null;
        $state = $data['state'] ?? null;
        $stateText = $data['state_text'] ?? null;

        return new self(
            id: is_numeric($id) ? (int) $id : 0,
            recipientType: is_scalar($recipientType) ? (string) $recipientType : '',
            recipientId: is_numeric($recipientId) ? (int) $recipientId : 0,
            splitType: is_scalar($splitType) ? (string) $splitType : '',
            amount: is_int($amount) || is_float($amount) || is_string($amount) ? $amount : 0,
            percentValue: is_int($percentValue) || is_float($percentValue) || is_string($percentValue) ? $percentValue : null,
            state: is_numeric($state) ? (int) $state : 0,
            stateText: is_scalar($stateText) ? (string) $stateText : '',
        );
    }
}
