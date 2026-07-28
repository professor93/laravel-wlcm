<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Uzbek\Wlcm\Events\PaymentSucceeded;
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

    /**
     * The document verified and the document dispatched must be the same bytes. With a
     * non-JSON content type `$request->all()` is the QUERY STRING while `getContent()` is
     * still the JSON body, so a captured signature authenticated the body while the query
     * decided what got dispatched — a full authentication bypass needing nothing but one
     * captured delivery.
     *
     * @return array<string, array{string|null}>
     */
    public static function nonJsonEncodings(): array
    {
        return ['text/plain' => ['text/plain'], 'no content type' => [null]];
    }

    #[DataProvider('nonJsonEncodings')]
    public function test_query_values_cannot_ride_a_body_signature(?string $contentType): void
    {
        Event::fake();

        $body = $this->body('2');
        $body['signature'] = $this->sign('1254', '7788', '2', '2026-07-24 12:00:00');

        // Attacker keeps the captured signature and body, and puts their own values in the
        // query string, which is what $request->all() reads under a non-JSON encoding.
        $forged = $body;
        $forged['order_id'] = '999999';
        $forged['payment_id'] = '888888';
        $forged['amount'] = '99999999';
        $forged['external_id'] = 'ATTACKER';

        $server = $contentType === null ? [] : ['CONTENT_TYPE' => $contentType];

        $response = $this->call(
            'POST',
            '/wlcm/webhook?'.http_build_query($forged),
            [], [], [], $server,
            (string) json_encode($body),
        );

        $this->assertNotSame(200, $response->getStatusCode(), 'query values rode a body signature');
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_an_ordinary_delivery_is_unaffected(): void
    {
        Event::fake();
        $data = $this->body('2');
        $data['signature'] = $this->sign('1254', '7788', '2', '2026-07-24 12:00:00');

        $this->postJson('/wlcm/webhook', $data)->assertOk();
    }
}
