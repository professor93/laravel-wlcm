<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class WebhookPayloadTest extends TestCase
{
    /** @param array<string, string> $overrides */
    private function payload(array $overrides = []): WebhookPayload
    {
        return WebhookPayload::fromArray($overrides + [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.50', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00', 'signature' => 'sig',
        ]);
    }

    public function test_amount_tiyin_string_math(): void
    {
        $this->assertSame(120050, $this->payload()->amountTiyin());
        $this->assertSame(1000, $this->payload(['amount' => '10'])->amountTiyin());
        $this->assertSame(1050, $this->payload(['amount' => '10.5'])->amountTiyin());
    }

    public function test_state_casting_and_helpers(): void
    {
        $p = $this->payload();
        $this->assertSame(2, $p->state);
        $this->assertSame(PaymentState::Success, $p->stateEnum());
        $this->assertTrue($p->isSuccess());
        $this->assertFalse($p->isCancelled());
    }

    public function test_signature_base_uses_raw_state(): void
    {
        $p = $this->payload(['state' => '-2']);
        $this->assertSame('1254:7788:-2:2026-07-24 12:00:00', $p->signatureBase());
    }

    public function test_int_typed_json_fields(): void
    {
        // Real deliveries send order_id/payment_id/state/timestamp as JSON ints.
        $p = WebhookPayload::fromArray([
            'external_id' => 'ORD-1', 'order_id' => 114, 'payment_id' => 98,
            'amount' => '100000.00', 'state' => 2, 'provider' => 'payme',
            'timestamp' => 1748765432000, 'signature' => 'sig',
        ]);

        $this->assertSame('114', $p->orderId);
        $this->assertSame('98', $p->paymentId);
        $this->assertSame(2, $p->state);
        $this->assertSame('2', $p->rawState);
        $this->assertSame('114:98:2:1748765432000', $p->signatureBase());
        $this->assertSame(10000000, $p->amountTiyin());
        $this->assertTrue($p->isSuccess());
    }

    public function test_hand_built_payload_derives_state_from_raw_state(): void
    {
        $p = new WebhookPayload(
            externalId: 'ORD-1',
            orderId: '1254',
            paymentId: '7788',
            amount: '1200.50',
            rawState: '2',
            provider: 'payme',
            timestamp: '2026-07-24 12:00:00',
            signature: 'sig',
        );

        $this->assertSame(2, $p->state);
        $this->assertSame('2', $p->rawState);
        $this->assertSame(PaymentState::Success, $p->stateEnum());
        $this->assertTrue($p->isSuccess());
        $this->assertSame('1254:7788:2:2026-07-24 12:00:00', $p->signatureBase());
    }
}
