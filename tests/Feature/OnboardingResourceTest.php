<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Exceptions\AuthorizationException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Partner\Resources\Onboarding;
use Uzbek\Wlcm\Tests\TestCase;

final class OnboardingResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // No api_key, no api_secret — the state a merchant is in before onboarding.
        $app['config']->set('wlcm.connections.bare', [
            'partner' => ['base_url' => 'https://api.wlcm.test/api/v1'],
        ]);
    }

    private function onboarding(): Onboarding
    {
        return Wlcm::connection('bare')->onboarding()->onboarding();
    }

    public function test_a_valid_token_reports_true(): void
    {
        // Shape from the contract recovered at 25a3e47. NOT probe-verified — a spare
        // token does not exist and a successful call consumes one.
        Http::fake(['api.wlcm.test/*' => Http::response(['valid' => true], 200)]);

        $this->assertTrue($this->onboarding()->validateToken('T'));

        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/partners/onboarding/'));
    }

    public function test_a_body_without_an_explicit_valid_flag_reports_false(): void
    {
        // The shape is unverified, so absence must not read as success.
        Http::fake(['api.wlcm.test/*' => Http::response(['something_else' => 1], 200)]);

        $this->assertFalse($this->onboarding()->validateToken('T'));
    }

    public function test_an_expired_token_throws_rather_than_reporting_false(): void
    {
        // Live-measured 400. "Expired" is not the same answer as "the server said no",
        // and a merchant mid-bootstrap needs to know which.
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'invalid_or_expired'], 400)]);

        try {
            $this->onboarding()->validateToken('T');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_or_expired', $e->errorCode);
            $this->assertSame(WlcmErrorCode::InvalidOrExpired, $e->errorCodeEnum());
        }
    }

    public function test_complete_returns_the_credentials(): void
    {
        // Live sample recorded alongside the recovered contract. Shape UNVERIFIED.
        Http::fake(['api.wlcm.test/*' => Http::response([
            'id' => 27, 'name' => 'ICAN', 'api_key' => 'wlcm_abc', 'api_secret' => 'sk_live_xyz',
            'warning' => 'Save these credentials now. They will never be shown again.',
        ], 200)]);

        $c = $this->onboarding()->complete('T', 'ICAN');

        $this->assertSame(27, $c->id);
        $this->assertSame('wlcm_abc', $c->apiKey);
        $this->assertSame('sk_live_xyz', $c->apiSecret);
        $this->assertStringContainsString('never be shown again', (string) $c->warning);

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            // Token in the query, name in the body — a token in the body is 400 token_required.
            return $r->method() === 'POST'
                && ($query['token'] ?? null) === 'T'
                && $r->body() === '{"name":"ICAN"}';
        });
    }

    public function test_the_minted_secret_never_reaches_a_log_store(): void
    {
        config()->set('wlcm.storage.logs', 'database');

        Http::fake(['api.wlcm.test/*' => Http::response([
            'id' => 27, 'name' => 'ICAN', 'api_key' => 'wlcm_abc', 'api_secret' => 'sk_live_xyz', 'warning' => 'w',
        ], 200)]);

        $this->onboarding()->complete('T', 'ICAN');

        $rows = WlcmLog::query()->get();
        // getAttribute for path: it is not in the model's @property docblock, and
        // property access on an undeclared column is what PHPStan flags.
        $blob = $rows->map(fn ($row) => json_encode([$row->request_body, $row->response_body, $row->getAttribute('path')]))->implode(' ');

        // NON-VACUITY FIRST. "The secret is absent" passes trivially when nothing was
        // logged at all, which is exactly how a masking test rots into proving nothing.
        $this->assertGreaterThan(0, $rows->count(), 'nothing was logged, so the assertions below prove nothing');
        $this->assertStringContainsString('ICAN', $blob, 'the response body was not logged, so the masking below is untested');

        // The secret is shown once and never again; an audit copy would outlive it.
        $this->assertStringNotContainsString('sk_live_xyz', $blob);
        $this->assertStringNotContainsString('wlcm_abc', $blob);
        // And the token must not survive in the logged path either.
        $this->assertStringNotContainsString('token=T', $blob);
    }

    public function test_a_forbidden_response_is_an_authorization_failure(): void
    {
        // Per the recovered contract: 403 ip_not_allowed / partner_inactive. UNVERIFIED.
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'ip_not_allowed'], 403)]);

        $this->expectException(AuthorizationException::class);

        $this->onboarding()->complete('T', 'ICAN');
    }

    public function test_a_missing_token_on_post_surfaces_its_machine_code(): void
    {
        // Live-measured: POST without a token is 400 token_required, a DIFFERENT code
        // from the GET path's 422.
        Http::fake(['api.wlcm.test/*' => Http::response(['detail' => 'token_required'], 400)]);

        try {
            $this->onboarding()->complete('', 'ICAN');
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('token_required', $e->errorCode);
        }
    }
}
