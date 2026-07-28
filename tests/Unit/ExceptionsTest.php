<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
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

    public function test_error_code_enum_resolves_known_code(): void
    {
        $e = new WlcmException('otp expired', errorCode: 'otp_expired');
        $this->assertSame(WlcmErrorCode::OtpExpired, $e->errorCodeEnum());
    }

    public function test_error_code_enum_is_null_for_unknown_code(): void
    {
        $e = new WlcmException('future', errorCode: 'some_future_code');
        $this->assertNull($e->errorCodeEnum());
    }

    public function test_error_code_enum_is_null_when_no_code(): void
    {
        $e = new WlcmException('no code');
        $this->assertNull($e->errorCodeEnum());
    }

    public function test_error_code_enum_covers_known_codes(): void
    {
        $known = array_column(WlcmErrorCode::cases(), 'value');

        foreach (['invalid_otp', 'insufficient_funds', 'hold_not_found', 'pinfl_not_match', 'external_id_already_exists'] as $code) {
            $this->assertContains($code, $known, $code);
        }
    }

    /**
     * @return array<string, array{mixed, string}>
     *
     * The offending value is printed to help a merchant, and an exception message reaches
     * log aggregators verbatim — so a newline in their config value must not be able to
     * forge a second log record, and a control byte must not corrupt the line.
     */
    public static function renderedValues(): array
    {
        return [
            'plain string' => ['off', "is 'off'"],
            'newline' => ["a\nFAKE: all good", "is 'a\\nFAKE: all good'"],
            'carriage return' => ["a\rb", "is 'a\\rb'"],
            'nul byte' => ["x\0y", "is 'x\\000y'"],
            'escape byte' => ["\x1b[2J", "is '\\033[2J'"],
            'integer' => [2, 'is 2'],
            'float' => [1.5, 'is 1.5'],
            'true' => [true, 'is true'],
            'false' => [false, 'is false'],
            'array' => [['a' => 1], 'is an array'],
        ];
    }

    #[DataProvider('renderedValues')]
    public function test_a_rejected_config_value_is_rendered_safely(mixed $value, string $expected): void
    {
        $message = MissingConfigurationException::forKey('wlcm.storage.transactions', 'true, false', $value)->getMessage();

        $this->assertStringContainsString($expected, $message);
        // Whatever it contained, the message stays one line.
        $this->assertSame(1, preg_match_all('/\R/', $message) + 1, 'the rendered value split the log line');
    }

    // Long values are capped, so a huge config string cannot flood a log record.
    public function test_a_long_value_is_truncated(): void
    {
        $message = MissingConfigurationException::forKey('wlcm.storage.transactions', 'true, false', str_repeat('A', 500))->getMessage();

        $this->assertStringContainsString(str_repeat('A', 40), $message);
        $this->assertStringNotContainsString(str_repeat('A', 41), $message);
    }

    /**
     * The brick-recovery runbook belongs only to keys read during BOOT. Appended to every
     * message it was simply false — `config:clear` works fine for a lazily-thrown key —
     * and it diluted the one message where it matters.
     */
    public function test_only_boot_read_keys_carry_the_recovery_runbook(): void
    {
        $boot = MissingConfigurationException::forKey('wlcm.webhook.enabled', 'true, false', 'off')->getMessage();
        $this->assertStringContainsString('bootstrap/cache/config.php', $boot);
        $this->assertStringContainsString('config:cache', $boot);

        foreach ([
            'wlcm.storage.transactions',
            'wlcm.storage.logs',
            'wlcm.webhook.lease_seconds',
            'wlcm.webhook.tolerance_seconds',
            'wlcm.connections.main.partner.webhook_secret',
            'wlcm.webhook route middleware',
        ] as $lazy) {
            $this->assertStringNotContainsString(
                'bootstrap/cache/config.php',
                MissingConfigurationException::forKey($lazy)->getMessage(),
                "{$lazy} claims config:clear will fail, which is false",
            );
        }
    }
}
