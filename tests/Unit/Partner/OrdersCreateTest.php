<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\OrderState;
use Uzbek\Wlcm\Exceptions\ConflictException;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class OrdersCreateTest extends TestCase
{
    public function test_it_creates_an_order(): void
    {
        // Live sandbox response, verbatim (order 642, probed 2026-07-27).
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 642, 'external_id' => 'ORD-7', 'amount' => 100000, 'status' => 'created',
        ], 200)]);

        $order = Wlcm::partner()->orders()->create(amountTiyin: 100000, externalId: 'ORD-7');

        $this->assertSame(642, $order->orderId);
        $this->assertSame('ORD-7', $order->externalId);
        $this->assertSame(100000, $order->amountTiyin);
        $this->assertSame(OrderState::Created, $order->statusEnum());

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/integrations/orders')
            && $r->body() === '{"amount":100000,"external_id":"ORD-7"}');
    }

    public function test_a_null_external_id_is_omitted_from_the_payload(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 644, 'external_id' => null, 'amount' => 100000, 'status' => 'created',
        ], 200)]);

        $order = Wlcm::partner()->orders()->create(amountTiyin: 100000);

        $this->assertNull($order->externalId);
        Http::assertSent(fn (Request $r): bool => $r->body() === '{"amount":100000}');
    }

    public function test_a_non_positive_amount_never_reaches_the_wire(): void
    {
        Http::fake();

        foreach ([0, -1, -500] as $amount) {
            try {
                Wlcm::partner()->orders()->create(amountTiyin: $amount);
                $this->fail("expected InvalidAmountException for {$amount}");
            } catch (InvalidAmountException $e) {
                $this->assertStringContainsString((string) $amount, $e->getMessage());
            }
        }

        // The server 422s these anyway; the point is that none of them left the process.
        Http::assertNothingSent();
    }

    public function test_a_duplicate_external_id_conflicts_without_a_machine_code(): void
    {
        // Live 409 body, verbatim: detail is a bare string, not the 422 list.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => 'Order already exists with this external_id',
        ], 409)]);

        try {
            Wlcm::partner()->orders()->create(amountTiyin: 100000, externalId: 'ORD-7');
            $this->fail('expected ConflictException');
        } catch (ConflictException $e) {
            // Prose, with spaces and capitals, must never be promoted to an error code.
            // Pinned so nobody later "fixes" this into passing it through.
            $this->assertNull($e->errorCode);
            $this->assertSame(409, $e->responseStatus);
        }
    }

    public function test_a_rejected_amount_surfaces_as_a_validation_failure(): void
    {
        // Live 422 body, verbatim.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'detail' => [[
                'type' => 'greater_than',
                'loc' => ['body', 'amount'],
                'msg' => 'Input should be greater than 0',
                'input' => 0,
                'ctx' => ['gt' => 0],
            ]],
        ], 422)]);

        $this->expectException(ValidationException::class);

        // Bypasses the local guard on purpose: this pins the server's arm, not ours.
        Wlcm::partner()->orders()->create(amountTiyin: 1, externalId: 'ORD-7');
    }
}
