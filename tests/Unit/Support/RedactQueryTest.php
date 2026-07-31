<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Support;

use Uzbek\Wlcm\Storage\Masker;
use Uzbek\Wlcm\Tests\TestCase;

final class RedactQueryTest extends TestCase
{
    public function test_it_hides_an_onboarding_token(): void
    {
        $this->assertSame(
            'https://api.wlcm.test/api/v1/partners/onboarding/?token=***',
            Masker::redactQuery('https://api.wlcm.test/api/v1/partners/onboarding/?token=live-secret-token'),
        );
    }

    public function test_it_hides_a_token_anywhere_in_a_longer_message(): void
    {
        // Guzzle appends " for {uri}" to every cURL error, so the token rides the
        // exception message as well as the url.
        $message = 'cURL error 28: Operation timed out for https://api.wlcm.test/api/v1/partners/onboarding/?token=live-secret-token';

        $this->assertStringNotContainsString('live-secret-token', Masker::redactQuery($message));
        $this->assertStringContainsString('token=***', Masker::redactQuery($message));
    }

    public function test_it_leaves_ordinary_query_values_alone(): void
    {
        // Redacting everything would destroy the diagnostics these exceptions exist for.
        $url = 'https://api.wlcm.test/api/v1/fiscalization?payment_id=179';

        $this->assertSame($url, Masker::redactQuery($url));
    }

    public function test_it_hides_every_sensitive_name_it_knows(): void
    {
        $url = 'https://api.wlcm.test/x?token=a&api_key=b&api_secret=c&signature=d&payment_id=9';
        $out = Masker::redactQuery($url);

        foreach (['a', 'b', 'c', 'd'] as $secret) {
            $this->assertStringNotContainsString('='.$secret.'&', $out.'&');
        }
        $this->assertStringContainsString('payment_id=9', $out);
    }

    public function test_a_url_with_no_query_is_untouched(): void
    {
        $url = 'https://api.wlcm.test/api/v1/partners/me';

        $this->assertSame($url, Masker::redactQuery($url));
    }

    public function test_an_empty_token_value_is_still_rewritten(): void
    {
        // Empty is a real case: ?token= returns 400 invalid_or_expired live. The output
        // must not imply a value was present.
        $this->assertSame('https://x.test/a?token=***', Masker::redactQuery('https://x.test/a?token='));
    }
}
