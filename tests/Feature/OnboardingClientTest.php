<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\OnboardingClient;
use Uzbek\Wlcm\Tests\TestCase;

final class OnboardingClientTest extends TestCase
{
    /** The whole point: a connection that has never seen an api key or secret. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('wlcm.connections.bare', [
            'partner' => ['base_url' => 'https://api.wlcm.test/api/v1'],
        ]);
    }

    public function test_it_builds_without_any_credentials(): void
    {
        $client = Wlcm::connection('bare')->onboarding();

        $this->assertInstanceOf(OnboardingClient::class, $client);
    }

    public function test_the_signed_client_still_refuses_the_same_connection(): void
    {
        // Proves the relaxation is scoped to onboarding and did not weaken partner().
        $this->expectException(MissingConfigurationException::class);

        Wlcm::connection('bare')->partner();
    }

    public function test_it_refuses_a_connection_with_no_base_url(): void
    {
        config()->set('wlcm.connections.nourl', ['partner' => ['api_key' => 'k']]);

        $this->expectException(MissingConfigurationException::class);

        Wlcm::connection('nourl')->onboarding();
    }

    public function test_it_sends_no_signature_headers(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['valid' => true], 200)]);

        Wlcm::connection('bare')->onboarding()->get('/partners/onboarding/', ['token' => 'T']);

        Http::assertSent(function (Request $r): bool {
            $names = array_map('strtolower', array_keys($r->headers()));

            return ! in_array('x-signature', $names, true)
                && ! in_array('x-api-key', $names, true)
                && ! in_array('x-timestamp', $names, true);
        });
    }

    public function test_it_preserves_a_trailing_slash(): void
    {
        // Measured live: /partners/onboarding without the slash resolves to a DIFFERENT,
        // signed route and answers 401 "Missing or invalid Authorization header".
        Http::fake(['api.wlcm.test/*' => Http::response(['valid' => true], 200)]);

        Wlcm::connection('bare')->onboarding()->get('/partners/onboarding/', ['token' => 'T']);

        Http::assertSent(fn (Request $r): bool => str_ends_with(
            (string) parse_url($r->url(), PHP_URL_PATH),
            '/partners/onboarding/',
        ));
    }

    public function test_it_puts_the_token_in_the_query_never_the_body(): void
    {
        // Measured live: a token in the body yields 400 token_required.
        Http::fake(['api.wlcm.test/*' => Http::response(['id' => 1], 200)]);

        Wlcm::connection('bare')->onboarding()->post('/partners/onboarding/', ['name' => 'Acme'], ['token' => 'T']);

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return ($query['token'] ?? null) === 'T'
                && $r->body() === '{"name":"Acme"}';
        });
    }

    public function test_a_mapped_error_carries_no_token(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'invalid_or_expired'], 400)]);

        try {
            Wlcm::connection('bare')->onboarding()->get('/partners/onboarding/', ['token' => 'live-token-xyz']);
            $this->fail('expected the mapped exception');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('live-token-xyz', (string) $e->requestPath);
            $this->assertSame('invalid_or_expired', $e->errorCode);
        }
    }
}
