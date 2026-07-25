<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\PartnerInfo;
use Uzbek\Wlcm\Tests\TestCase;

final class PartnersTest extends TestCase
{
    public function test_me(): void
    {
        // Live sandbox /partners/me response, verbatim.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'id' => 31, 'name' => 'iCAN (test)', 'company_name' => 'OOO «ICAN IT-GROUP»',
            'receipt_item_name' => null, 'email' => 'icanitgroup@gmail.com', 'phone' => null,
            'partner_type' => '', 'status' => '1', 'is_active' => true,
            'created_at' => '2026-07-24T07:35:51.122265Z', 'sub_partners' => [],
            'cancel_payment_enabled' => false, 'show_phone_number' => false,
        ], 200)]);

        $me = Wlcm::partner()->partners()->me();

        $this->assertSame(31, $me->id);
        $this->assertSame('iCAN (test)', $me->name);
        $this->assertSame('OOO «ICAN IT-GROUP»', $me->companyName);
        $this->assertNull($me->receiptItemName);
        $this->assertSame('icanitgroup@gmail.com', $me->email);
        $this->assertNull($me->phone);
        $this->assertSame('', $me->partnerType);
        $this->assertSame('1', $me->status);
        $this->assertTrue($me->isActive);
        $this->assertSame('2026-07-24T07:35:51.122265Z', $me->createdAt);
        $this->assertSame([], $me->subPartners);
        $this->assertFalse($me->cancelPaymentEnabled);
        $this->assertFalse($me->showPhoneNumber);
        $this->assertSame('iCAN (test)', $me->raw['name']);

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/partners/me'));
    }

    public function test_partner_info_tolerates_empty_server_shape(): void
    {
        $me = PartnerInfo::fromArray([]);

        $this->assertSame(0, $me->id);
        $this->assertSame('', $me->name);
        $this->assertNull($me->companyName);
        $this->assertNull($me->receiptItemName);
        $this->assertNull($me->email);
        $this->assertNull($me->phone);
        $this->assertSame('', $me->partnerType);
        $this->assertSame('', $me->status);
        $this->assertFalse($me->isActive);
        $this->assertNull($me->createdAt);
        $this->assertSame([], $me->subPartners);
        $this->assertFalse($me->cancelPaymentEnabled);
        $this->assertFalse($me->showPhoneNumber);
        $this->assertSame([], $me->raw);
    }

    public function test_partner_info_tolerates_wrong_types(): void
    {
        $me = PartnerInfo::fromArray([
            'id' => ['x'], 'name' => ['x'], 'company_name' => ['x'], 'status' => ['x'],
            'sub_partners' => 'not-a-list',
        ]);

        $this->assertSame(0, $me->id);
        $this->assertSame('', $me->name);
        $this->assertNull($me->companyName);
        $this->assertSame('', $me->status);
        $this->assertSame([], $me->subPartners);
    }

    public function test_partner_info_drops_non_array_sub_partners(): void
    {
        $me = PartnerInfo::fromArray(['id' => 31, 'name' => 'x', 'sub_partners' => ['oops', ['id' => 9]]]);

        $this->assertSame([['id' => 9]], $me->subPartners);
    }
}
