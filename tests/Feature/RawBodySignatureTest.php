<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Event;
use Uzbek\Wlcm\Tests\TestCase;

/**
 * A stock Laravel app runs TrimStrings and ConvertEmptyStringsToNull in the global stack
 * BEFORE route middleware, so anything read through $request->input()/all() has already
 * been rewritten. Verifying an HMAC against rewritten input means a delivery WLCM signed
 * correctly over a value with surrounding whitespace is refused 403 — a payment event
 * lost to our own verification, which the merchant cannot fix without disabling
 * TrimStrings for their whole application.
 */
final class RawBodySignatureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Testbench ships a bare stack; put the two the framework installs by default back.
        $kernel = $app->make(Kernel::class);
        $kernel->pushMiddleware(TrimStrings::class);
        $kernel->pushMiddleware(ConvertEmptyStringsToNull::class);
    }

    /** @return array<string, string> */
    private function body(string $state): array
    {
        return [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => $state, 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
    }

    private function sign(string $orderId, string $paymentId, string $state, string $timestamp): string
    {
        return hash_hmac('sha256', "{$orderId}:{$paymentId}:{$state}:{$timestamp}", 'whsec_test');
    }

    public function test_a_signature_over_the_untrimmed_wire_value_verifies(): void
    {
        Event::fake();
        $data = $this->body(' 2');
        // WLCM signed what it sent, whitespace included.
        $data['signature'] = $this->sign('1254', '7788', ' 2', '2026-07-24 12:00:00');

        $this->postJson('/wlcm/webhook', $data)->assertOk();
    }

    public function test_a_signature_over_the_trimmed_value_is_refused(): void
    {
        Event::fake();
        $data = $this->body(' 2');
        // Signed over what the framework rewrote it to, which WLCM never sent.
        $data['signature'] = $this->sign('1254', '7788', '2', '2026-07-24 12:00:00');

        $this->postJson('/wlcm/webhook', $data)->assertStatus(403);
    }

    public function test_an_ordinary_delivery_is_unaffected(): void
    {
        Event::fake();
        $data = $this->body('2');
        $data['signature'] = $this->sign('1254', '7788', '2', '2026-07-24 12:00:00');

        $this->postJson('/wlcm/webhook', $data)->assertOk();
    }
}
