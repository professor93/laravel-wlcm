<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Partner\RequestSigner;

final class RequestSignerTest extends TestCase
{
    private const string SECRET = 'sk_test_secret';

    private const string TS = '1753305600000';

    public function test_post_body_vector(): void
    {
        $body = '{"external_id":"ORD-100045","amount":120000,"payment_provider":"payme","return_url":"https://merchant.example.com/payment/return"}';
        $sig = new RequestSigner(self::SECRET)->sign('POST', 'https://api.wlcm.test/api/v1/integrations/checkout', self::TS, $body);
        $this->assertSame('d33a9085d699e3982243953224b290e3cc9e9447336a091388ededeefab4c173', $sig);
    }

    public function test_get_empty_body_vector(): void
    {
        $sig = new RequestSigner(self::SECRET)->sign('GET', 'https://api.wlcm.test/api/v1/integrations/me', self::TS, '');
        $this->assertSame('4bd963a212964438dadbd625c649fb08281acdfe07dcbfd7aa51215853bb67e5', $sig);
    }

    public function test_query_params_sorted_vector(): void
    {
        $url = 'https://api.wlcm.test/api/v1/fiscalization?payment_id=7788&external_id=ORD-1';
        $this->assertSame(
            '/api/v1/fiscalization?external_id=ORD-1&payment_id=7788',
            new RequestSigner(self::SECRET)->canonicalPath($url),
        );
        $sig = new RequestSigner(self::SECRET)->sign('GET', $url, self::TS, '');
        $this->assertSame('e1cb4f632a8a79326c7bc7dddc850f4d4812a44e5ec33a3efa2fd3b45d4741a5', $sig);
    }

    public function test_space_encodes_as_plus_vector(): void
    {
        $url = 'https://api.wlcm.test/api/v1/fiscalization?external_id=ORD 1&b=2';
        $this->assertSame(
            '/api/v1/fiscalization?b=2&external_id=ORD+1',
            new RequestSigner(self::SECRET)->canonicalPath($url),
        );
        $sig = new RequestSigner(self::SECRET)->sign('GET', $url, self::TS, '');
        $this->assertSame('c04287d5f121a6e43da721a2d1fe9c8a96395ca9bf000a733729cdf2266441aa', $sig);
    }

    public function test_method_uppercased(): void
    {
        $a = new RequestSigner(self::SECRET)->sign('get', 'https://x.test/api/v1/integrations/me', self::TS, '');
        $b = new RequestSigner(self::SECRET)->sign('GET', 'https://x.test/api/v1/integrations/me', self::TS, '');
        $this->assertSame($b, $a);
    }

    public function test_numeric_string_tie_sorts_lexicographic_vector(): void
    {
        $url = 'https://api.wlcm.test/api/v1/fiscalization?item_id=10&item_id=9';
        $this->assertSame(
            '/api/v1/fiscalization?item_id=10&item_id=9',
            new RequestSigner(self::SECRET)->canonicalPath($url),
        );
        $sig = new RequestSigner(self::SECRET)->sign('GET', $url, self::TS, '');
        $this->assertSame('a9fd17149b0b79d56b30fa6fb324434125d632bf7cc129200a1a673826de95a0', $sig);
    }

    public function test_tilde_stays_literal_vector(): void
    {
        $url = 'https://api.wlcm.test/api/v1/fiscalization?note=foo~bar&a=x y';
        $this->assertSame(
            '/api/v1/fiscalization?a=x+y&note=foo~bar',
            new RequestSigner(self::SECRET)->canonicalPath($url),
        );
        $sig = new RequestSigner(self::SECRET)->sign('GET', $url, self::TS, '');
        $this->assertSame('59c06f7f4648864bf16b265d9f90fa727a70ecf67d79dd86bc4c5980b2561021', $sig);
    }
}
