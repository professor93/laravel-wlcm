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
}
