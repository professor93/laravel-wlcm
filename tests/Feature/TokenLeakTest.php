<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Exceptions\ConnectionException;
use Uzbek\Wlcm\Exceptions\NotFoundException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;

/**
 * A live onboarding token authenticates a one-shot partner creation. It rides ?token=,
 * and exception context is what a host's default handler ships to its error tracker.
 */
final class TokenLeakTest extends TestCase
{
    private const TOKEN = 'live-onboarding-token-value';

    public function test_a_mapped_error_response_does_not_carry_the_token(): void
    {
        // The ORDINARY onboarding failure: bogus or expired token, measured live as
        // 400 {"detail":"invalid_or_expired"}.
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'invalid_or_expired'], 404)]);

        try {
            Wlcm::partner()->get('/partners/onboarding/', ['token' => self::TOKEN]);
            $this->fail('expected the mapped exception');
        } catch (NotFoundException $e) {
            $this->assertStringNotContainsString(self::TOKEN, (string) $e->requestPath);
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringContainsString('token=***', (string) $e->requestPath);
        }
    }

    public function test_a_transport_failure_does_not_carry_the_token(): void
    {
        // Guzzle appends " for {uri}" to every cURL error, so the token rides the
        // message as well as the url.
        Http::fake(fn () => throw new HttpConnectionException(
            'cURL error 28: Operation timed out for https://api.wlcm.test/api/v1/partners/onboarding/?token='.self::TOKEN
        ));

        try {
            Wlcm::partner()->get('/partners/onboarding/', ['token' => self::TOKEN]);
            $this->fail('expected a ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, (string) $e->requestPath);
        }
    }

    public function test_an_ordinary_query_value_still_reaches_the_exception(): void
    {
        // Redacting everything would destroy what these exceptions are for.
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'not found'], 404)]);

        try {
            Wlcm::partner()->get('/fiscalization', ['payment_id' => 179]);
            $this->fail('expected the mapped exception');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('payment_id=179', (string) $e->requestPath);
        }
    }
}
