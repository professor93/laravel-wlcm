<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

final class ProvidersOrdersTest extends TestCase
{
    public function test_providers_list(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            ['name' => 'payme', 'label' => 'Payme', 'sort_order' => 1, 'is_active' => true, 'icon' => 'https://cdn.wlcm.uz/payme.svg'],
            ['name' => 'mock_payme', 'label' => 'Mock Payme', 'sort_order' => 2, 'is_active' => true, 'icon' => null],
            ['name' => 'uzum', 'label' => 'Uzum', 'sort_order' => 3, 'is_active' => false, 'icon' => null],
        ], 200)]);

        $providers = Wlcm::partner()->providers()->all();

        $this->assertCount(3, $providers);
        $this->assertSame('payme', $providers[0]->name);
        $this->assertSame('Payme', $providers[0]->label);
        $this->assertSame(1, $providers[0]->sortOrder);
        $this->assertTrue($providers[0]->isActive);
        $this->assertSame('https://cdn.wlcm.uz/payme.svg', $providers[0]->icon);
        $this->assertNull($providers[1]->icon);
        $this->assertFalse($providers[2]->isActive);

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/payments/providers'));
    }

    public function test_providers_list_unwraps_pagination(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'count' => 1, 'next' => null, 'previous' => null,
            'results' => [
                ['name' => 'payme', 'label' => 'Payme', 'sort_order' => 1, 'is_active' => true, 'icon' => null],
            ],
        ], 200)]);

        $providers = Wlcm::partner()->providers()->all();

        $this->assertCount(1, $providers);
        $this->assertSame('payme', $providers[0]->name);
    }

    public function test_order_status(): void
    {
        // Live sandbox response, verbatim: amount is a SUM float.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'amount' => 1000.0, 'status' => "To'lov jarayonda", 'state' => 1,
            'is_paid' => false, 'is_pending' => true, 'is_cancelled' => false, 'is_completed' => false,
            'status_explanation' => ['status' => 'pending', 'state' => '1', 'description' => "To'lov jarayonda"],
            'extra_data' => [],
        ], 200)]);

        $status = Wlcm::partner()->orders()->status(410);

        $this->assertSame(410, $status->orderId);
        $this->assertSame(1000.0, $status->amount);
        $this->assertSame(100000, $status->amountTiyin());
        $this->assertSame("To'lov jarayonda", $status->status);
        $this->assertSame(PaymentState::Initiating, $status->stateEnum());
        $this->assertFalse($status->isPaid);
        $this->assertTrue($status->isPending);
        $this->assertFalse($status->isCancelled);
        $this->assertFalse($status->isCompleted);
        $this->assertSame('pending', $status->statusExplanation['status']);
        $this->assertSame([], $status->extraData);

        Http::assertSent(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/orders/410/status'));
    }

    public function test_paid_order_status_shape(): void
    {
        // Live sandbox paid response, verbatim: no top-level state; gains payment fields.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'payment_id' => 170, 'order_id' => 410, 'gateway' => 'mock_payme', 'amount' => 1000.0,
            'status' => 'paid', 'transaction_id' => 'mock_tx_1784911325121',
            'is_paid' => true, 'is_pending' => false, 'is_cancelled' => false, 'is_completed' => false,
            'status_explanation' => ['status' => 'paid', 'state' => '2', 'description' => "To'lov muvaffaqiyatli amalga oshirildi"],
            'performed_at' => '2026-07-24T21:51:57.249121+05:00',
            'extra_data' => [],
        ], 200)]);

        $status = Wlcm::partner()->orders()->status(410);

        $this->assertSame(170, $status->paymentId);
        $this->assertSame('mock_payme', $status->gateway);
        $this->assertSame('mock_tx_1784911325121', $status->transactionId);
        $this->assertSame('2026-07-24T21:51:57.249121+05:00', $status->performedAt);
        $this->assertTrue($status->isPaid);
        $this->assertSame('2', $status->statusExplanation['state']);
        $this->assertSame(PaymentState::Success, $status->stateEnum());
        $this->assertSame(100000, $status->amountTiyin());
    }

    public function test_pending_order_status_has_null_payment_fields(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'amount' => 1000.0, 'status' => "To'lov jarayonda", 'state' => 1,
            'is_paid' => false, 'is_pending' => true, 'is_cancelled' => false, 'is_completed' => false,
            'status_explanation' => ['status' => 'pending', 'state' => '1', 'description' => "To'lov jarayonda"],
            'extra_data' => [],
        ], 200)]);

        $status = Wlcm::partner()->orders()->status(410);

        $this->assertNull($status->paymentId);
        $this->assertNull($status->gateway);
        $this->assertNull($status->transactionId);
        $this->assertNull($status->performedAt);
    }

    public function test_order_status_without_state_anywhere_is_null(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'amount' => 1000.0, 'status' => 'paid',
            'is_paid' => true, 'is_pending' => false, 'is_cancelled' => false, 'is_completed' => false,
            'status_explanation' => ['status' => 'paid', 'description' => "To'landi"],
            'extra_data' => [],
        ], 200)]);

        $status = Wlcm::partner()->orders()->status(410);

        $this->assertNull($status->state);
        $this->assertNull($status->stateEnum());
    }

    public function test_order_status_with_non_numeric_state_is_null(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 410, 'amount' => 1000.0, 'status' => 'weird', 'state' => 'pending',
            'status_explanation' => [], 'extra_data' => [],
        ], 200)]);

        $status = Wlcm::partner()->orders()->status(410);

        $this->assertNull($status->state);
        $this->assertNull($status->stateEnum());
    }

    public function test_order_amount_tiyin_avoids_float_drift(): void
    {
        $amount = 0.0;
        Http::fake(function () use (&$amount) {
            return Http::response(['order_id' => 1, 'amount' => $amount, 'state' => 2], 200);
        });

        foreach ([
            [19.99, 1999],
            [1234.56, 123456],
            [0.1 + 0.2, 30],
            [1000.0, 100000],
        ] as [$sum, $tiyin]) {
            $amount = $sum;
            $this->assertSame($tiyin, Wlcm::partner()->orders()->status(1)->amountTiyin());
        }
    }
}
