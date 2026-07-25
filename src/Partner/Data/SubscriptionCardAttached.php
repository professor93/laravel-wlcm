<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

/**
 * @phpstan-type SubscriptionCardAttachedShape array{card_id?: WlcmJsonScalar, otp_sent_phone?: WlcmJsonScalar, reused?: WlcmJsonScalar}
 */
final readonly class SubscriptionCardAttached
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public int $cardId,
        public ?string $otpSentPhone,
        public bool $reused,
        public array $raw,
    ) {}

    /** @param SubscriptionCardAttachedShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            cardId: (int) ($data['card_id'] ?? 0),
            otpSentPhone: isset($data['otp_sent_phone']) ? (string) $data['otp_sent_phone'] : null,
            reused: (bool) ($data['reused'] ?? false),
            raw: $data,
        );
    }
}
