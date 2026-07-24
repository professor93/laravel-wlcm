<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner\Data;

final readonly class FiscalRefundResponse
{
    public function __construct(
        public string $message,
        public int $fiscalId,
    ) {}

    /** @param FiscalRefundResponseShape $data */
    public static function fromArray(array $data): self
    {
        return new self(
            message: (string) ($data['message'] ?? ''),
            fiscalId: (int) ($data['fiscal_id'] ?? 0),
        );
    }
}
