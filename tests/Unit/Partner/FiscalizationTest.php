<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\FiscalReceipt;
use Uzbek\Wlcm\Tests\TestCase;

final class FiscalizationTest extends TestCase
{
    public function test_get_requires_a_parameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Wlcm::partner()->fiscalization()->get();
    }

    public function test_get_by_payment_id(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'id' => 501, 'payment_id' => 7788, 'order_id' => 1254,
            'fiscal_sign' => 'ABCD1234', 'fiscal_number' => '000123456789',
            'qr_code_url' => 'https://ofd.example.com/qr/000123456789',
            'receipt_type' => 'SALE', 'is_refund' => false, 'printed_at' => '2026-03-18T10:30:00',
        ], 200)]);

        $r = Wlcm::partner()->fiscalization()->get(paymentId: 7788);

        $this->assertSame(501, $r->id);
        $this->assertFalse($r->isRefund);
        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), 'payment_id=7788'));
    }

    public function test_register(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'message' => 'Fiscal receipt created', 'fiscal_id' => 501,
            'fiscal_number' => '000123456789', 'fiscal_sign' => 'ABCD1234',
            'qr_code_url' => 'https://ofd.example.com/qr/000123456789',
            'is_refund' => false, 'printed_at' => '2026-03-18T10:30:00',
        ], 200)]);

        $r = Wlcm::partner()->fiscalization()->register(
            items: [['title' => 'Premium subscription', 'price' => 120000, 'count' => 1, 'vat_percent' => 12]],
            paymentId: 7788,
        );

        $this->assertSame(501, $r->fiscalId);
    }

    public function test_register_requires_items_and_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Wlcm::partner()->fiscalization()->register(items: []);
    }

    public function test_refund(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'message' => 'Refund fiscal receipt created', 'fiscal_id' => 777,
        ], 200)]);

        $r = Wlcm::partner()->fiscalization()->refund(externalId: 'ORD-1');

        $this->assertSame(777, $r->fiscalId);
    }

    public function test_fiscal_receipt_tolerates_empty_server_shape(): void
    {
        $r = FiscalReceipt::fromArray([]);

        $this->assertSame(0, $r->id);
        $this->assertSame(0, $r->paymentId);
        $this->assertSame(0, $r->orderId);
        $this->assertSame('', $r->fiscalSign);
        $this->assertSame('', $r->fiscalNumber);
        $this->assertSame('', $r->qrCodeUrl);
        $this->assertSame('', $r->receiptType);
        $this->assertFalse($r->isRefund);
        $this->assertNull($r->printedAt);
    }

    public function test_fiscal_receipt_tolerates_wrong_types(): void
    {
        $r = FiscalReceipt::fromArray(['id' => ['x'], 'fiscal_sign' => ['x'], 'printed_at' => ['x']]);

        $this->assertSame(0, $r->id);
        $this->assertSame('', $r->fiscalSign);
        $this->assertNull($r->printedAt);
    }
}
