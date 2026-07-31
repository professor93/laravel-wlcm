<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Partner\Data\OnboardingCredentials;
use Uzbek\Wlcm\Tests\TestCase;

final class OnboardingCredentialsTest extends TestCase
{
    public function test_it_hydrates_the_recorded_live_sample(): void
    {
        // Live sample recovered alongside the contract at commit 25a3e47. NOT probe-verified:
        // a successful POST consumes a token and mints a real partner.
        $c = OnboardingCredentials::fromArray([
            'id' => 27,
            'name' => 'ICAN',
            'api_key' => 'wlcm_abc123',
            'api_secret' => 'sk_secret_value',
            'warning' => 'Save these credentials now. They will never be shown again.',
        ]);

        $this->assertSame(27, $c->id);
        $this->assertSame('ICAN', $c->name);
        $this->assertSame('wlcm_abc123', $c->apiKey);
        $this->assertSame('sk_secret_value', $c->apiSecret);
        $this->assertSame('Save these credentials now. They will never be shown again.', $c->warning);
    }

    public function test_it_survives_a_response_missing_every_optional_key(): void
    {
        // The shape is unverified against the current API, so a drift must degrade to
        // nulls rather than throw on the one call a merchant cannot repeat.
        $c = OnboardingCredentials::fromArray(['id' => 27]);

        $this->assertSame(27, $c->id);
        $this->assertNull($c->name);
        $this->assertNull($c->apiKey);
        $this->assertNull($c->apiSecret);
        $this->assertNull($c->warning);
    }

    public function test_a_non_numeric_id_falls_back_to_zero(): void
    {
        $this->assertSame(0, OnboardingCredentials::fromArray(['id' => ['nested']])->id);
    }

    public function test_a_numeric_string_id_is_coerced(): void
    {
        $this->assertSame(27, OnboardingCredentials::fromArray(['id' => '27'])->id);
    }

    public function test_the_measured_error_codes_resolve_to_enum_cases(): void
    {
        // Both live-measured 2026-07-31: bare snake_case, so they pass ErrorMapper's
        // machine-code guard and must have cases here.
        $this->assertSame(WlcmErrorCode::InvalidOrExpired, WlcmErrorCode::tryFrom('invalid_or_expired'));
        $this->assertSame(WlcmErrorCode::TokenRequired, WlcmErrorCode::tryFrom('token_required'));
    }
}
