<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Partner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Exceptions\AuthenticationException;
use Uzbek\Wlcm\Exceptions\AuthorizationException;
use Uzbek\Wlcm\Exceptions\ConnectionException;
use Uzbek\Wlcm\Exceptions\ServerException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Exceptions\WlcmException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\RequestSigner;
use Uzbek\Wlcm\Tests\TestCase;

final class PartnerClientTest extends TestCase
{
    public function test_post_sends_signed_headers_and_exact_body(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['ok' => true], 201)]);

        Wlcm::partner()->post('/integrations/checkout', [
            'amount' => 120000,
            'return_url' => 'https://shop.test/return',
        ]);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();
            $signer = new RequestSigner('sk_test_secret');
            $expected = $signer->sign('POST', $request->url(), $request->header('X-Timestamp')[0], $body);

            return $request->hasHeader('X-API-Key', 'pk_test_key')
                && $request->header('X-Signature')[0] === $expected
                && $body === '{"amount":120000,"return_url":"https://shop.test/return"}'
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_get_signs_empty_body_and_sorted_query(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([], 200)]);

        Wlcm::partner()->get('/fiscalization', ['payment_id' => 7788, 'external_id' => 'ORD-1']);

        Http::assertSent(function (Request $request): bool {
            $signer = new RequestSigner('sk_test_secret');
            $expected = $signer->sign('GET', $request->url(), $request->header('X-Timestamp')[0], '');

            return $request->header('X-Signature')[0] === $expected;
        });
    }

    public function test_get_retries_on_transport_error(): void
    {
        Http::fake([
            'api.wlcm.test/*' => Http::sequence()
                ->pushFailedConnection()
                ->push(['ok' => true], 200),
        ]);

        $result = Wlcm::partner()->get('/partners/me');

        $this->assertSame(['ok' => true], $result);
        Http::assertSentCount(2);
    }

    public function test_post_never_retries(): void
    {
        Http::fake([
            'api.wlcm.test/*' => Http::sequence()
                ->pushFailedConnection()
                ->push(['ok' => true], 201),
        ]);

        $this->expectException(ConnectionException::class);

        try {
            Wlcm::partner()->post('/integrations/checkout', ['amount' => 1]);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_status_code_mapping(): void
    {
        // Http::fake() accumulates stub callbacks and the first match wins, so refaking
        // per-iteration on the same URL pattern would keep serving the first status.
        // Register once and drive the response through a captured, mutable status.
        $status = 0;
        Http::fake(function () use (&$status) {
            return Http::response(['detail' => 'err'], $status);
        });

        foreach ([
            [401, AuthenticationException::class],
            [403, AuthorizationException::class],
            [422, ValidationException::class],
            [500, ServerException::class],
        ] as [$expectedStatus, $exception]) {
            $status = $expectedStatus;
            try {
                Wlcm::partner()->get('/partners/me');
                $this->fail("expected {$exception} for {$expectedStatus}");
            } catch (WlcmException $e) {
                $this->assertInstanceOf($exception, $e);
                $this->assertSame($expectedStatus, $e->responseStatus);
                $this->assertSame('main', $e->connectionName);
            }
        }
    }
}
