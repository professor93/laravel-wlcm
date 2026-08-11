<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\DataProvider;
use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Tests\TestCase;

final class WlcmErrorCodeTest extends TestCase
{
    /**
     * Every rejection the signature envelope can emit, live-observed against
     * apidev.wlcm.uz. All six answer 401 before any handler runs.
     *
     * @return list<array{string}>
     */
    public static function envelopeCodes(): array
    {
        return [
            ['api_key_missing'],
            ['invalid_api_key'],
            ['signature_missing'],
            ['invalid_signature'],
            ['invalid_timestamp'],
            ['timestamp_expired'],
        ];
    }

    #[DataProvider('envelopeCodes')]
    public function test_envelope_rejection_resolves_to_a_case(string $code): void
    {
        $case = WlcmErrorCode::tryFrom($code);

        $this->assertNotNull(
            $case,
            "server code '{$code}' has no enum case, so errorCodeEnum() returns null",
        );
        $this->assertSame($code, $case->value);
    }
}
