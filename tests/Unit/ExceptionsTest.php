<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Exceptions\WlcmException;

final class ExceptionsTest extends TestCase
{
    public function test_validation_exception_exposes_errors(): void
    {
        $e = new ValidationException('validation failed', errors: ['amount' => ['must be > 0']]);
        $this->assertSame(['amount' => ['must be > 0']], $e->errors());
        $this->assertInstanceOf(WlcmException::class, $e);
    }

    public function test_base_exception_exposes_error_code(): void
    {
        $e = new WlcmException('card blocked', errorCode: 'card_is_blocked');
        $this->assertSame('card_is_blocked', $e->errorCode());
        $this->assertSame(WlcmErrorCode::CardIsBlocked, WlcmErrorCode::tryFrom($e->errorCode()));
    }

    public function test_error_code_enum_covers_known_codes(): void
    {
        foreach (['invalid_otp', 'insufficient_funds', 'hold_not_found', 'pinfl_not_match', 'external_id_already_exists'] as $code) {
            $this->assertNotNull(WlcmErrorCode::tryFrom($code), $code);
        }
    }
}
