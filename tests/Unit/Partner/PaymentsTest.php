<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class PaymentsTest extends TestCase
{
    public function test_cancel_sends_integer_reason(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['success' => true], 200)]);

        $result = Wlcm::partner()->payments()->cancel(98, 1);

        $this->assertSame(['success' => true], $result);
        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/integrations/payment/cancel')
            && $r->body() === '{"payment_id":98,"reason":1}');
    }

    public function test_cancel_without_reason_omits_field(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['success' => true], 200)]);

        Wlcm::partner()->payments()->cancel(98);

        Http::assertSent(fn (Request $r): bool => $r->body() === '{"payment_id":98}');
    }

    public function test_confirm_paylov_sends_camel_case(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['success' => true], 200)]);

        $result = Wlcm::partner()->payments()->confirmPaylov('6eb241ad-eab8-418f-9a66-55db127bec30', '888888');

        $this->assertSame(['success' => true], $result);
        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/integrations/payment/confirm')
            && $r->body() === '{"otp":"888888","transactionId":"6eb241ad-eab8-418f-9a66-55db127bec30"}');
    }
}
