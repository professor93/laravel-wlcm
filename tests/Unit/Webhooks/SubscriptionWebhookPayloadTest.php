<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;

final class SubscriptionWebhookPayloadTest extends TestCase
{
    public function test_from_array_and_signature_base(): void
    {
        // Documented subscription webhook payload, verbatim shape.
        $p = SubscriptionWebhookPayload::fromArray([
            'event' => 'subscription.activated',
            'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001',
            'subscription_id' => 8,
            'amount' => '49900.00',
            'status' => 'active',
            'timestamp' => 1745740800000,
            'signature' => 'a3f1c2d4',
        ], 'main');

        $this->assertSame('subscription.activated', $p->event);
        $this->assertSame('order-1001', $p->externalOrderId);
        $this->assertSame('user-1001', $p->externalUserId);
        $this->assertSame(8, $p->subscriptionId);
        $this->assertSame('49900.00', $p->amount);
        $this->assertSame(4990000, $p->amountTiyin());
        $this->assertSame('active', $p->status);
        $this->assertSame('1745740800000', $p->timestamp);
        $this->assertSame('a3f1c2d4', $p->signature);
        $this->assertSame('main', $p->connection);
        $this->assertSame('order-1001:subscription.activated:1745740800000', $p->signatureBase());
    }
}
