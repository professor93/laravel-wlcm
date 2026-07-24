<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Storage\Masker;

final class MaskerTest extends TestCase
{
    public function test_pan_masked_first6_last4(): void
    {
        $out = Masker::mask(['card_number' => '9860991234569999']);
        $this->assertSame('986099******9999', $out['card_number']);
    }

    public function test_short_pan_fully_masked(): void
    {
        $out = Masker::mask(['card_number' => '12345']);
        $this->assertSame('***', $out['card_number']);
    }

    public function test_sensitive_values_starred(): void
    {
        $out = Masker::mask(['otp' => '111111', 'expire_date' => '3003', 'password' => 'x']);
        $this->assertSame(['otp' => '***', 'expire_date' => '***', 'password' => '***'], $out);
    }

    public function test_secrets_removed(): void
    {
        $out = Masker::mask(['api_secret' => 's', 'access_token' => 't', 'amount' => 100]);
        $this->assertSame(['amount' => 100], $out);
    }

    public function test_recursive(): void
    {
        $out = Masker::mask(['nested' => [['cardNumber' => '9860991234569999']]]);
        $this->assertSame('986099******9999', $out['nested'][0]['cardNumber']);
    }

    public function test_integer_pan_masked(): void
    {
        $out = Masker::mask(['card_number' => 9860991234569999]);
        $this->assertSame('986099******9999', $out['card_number']);
    }

    public function test_webhook_and_api_secrets_removed(): void
    {
        $out = Masker::mask(['secret_key' => 'whsec_live', 'api_key' => 'pk_live', 'client_secret' => 'cs', 'card_token' => 'tok', 'url' => 'https://x.test']);
        $this->assertSame(['url' => 'https://x.test'], $out);
    }

    public function test_pan_shorter_than_13_digits_fully_masked(): void
    {
        $out = Masker::mask(['card_number' => '1234567890', 'pan' => '123456789012']);
        $this->assertSame('***', $out['card_number']);
        $this->assertSame('***', $out['pan']);
    }

    public function test_pan_13_digits_keeps_first6_last4(): void
    {
        $out = Masker::mask(['card_number' => '1234567890123']);
        $this->assertSame('123456***0123', $out['card_number']);
    }

    public function test_pan_wrapped_in_array_fully_masked(): void
    {
        $out = Masker::mask(['card_number' => ['9860991234569999'], 'otp' => ['111111']]);
        $this->assertSame('***', $out['card_number']);
        $this->assertSame('***', $out['otp']);
    }

    public function test_pan_nested_map_fully_masked(): void
    {
        $out = Masker::mask(['card_number' => ['full' => '9860991234569999', 'masked' => 'x']]);
        $this->assertSame('***', $out['card_number']);
    }

    public function test_camel_case_secret_keys_removed(): void
    {
        $out = Masker::mask(['secretKey' => 'w', 'apiKey' => 'p', 'clientSecret' => 'c', 'cardToken' => 't', 'accessToken' => 'a', 'refreshToken' => 'r', 'ok' => 1]);
        $this->assertSame(['ok' => 1], $out);
    }

    public function test_whitespace_padded_secret_key_removed(): void
    {
        $out = Masker::mask([' secret_key' => 'w', 'amount' => 5]);
        $this->assertSame(['amount' => 5], $out);
    }
}
