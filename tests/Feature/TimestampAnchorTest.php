<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;

/**
 * PCRE `$` matches before a final newline, so `\d{10}$` accepts "1785065886\n".
 *
 * This project has already paid for that once on a money field: an amount regex anchored
 * with `$` let "5000\n" parse as 5000 instead of 500000, a 100x understatement, and
 * `Tiyin::fromSumString` carries a `\z` and a comment saying exactly why. The timestamp
 * guards in the same delivery had drifted back to `$`, so one delivery was being read by
 * two rules that disagreed about whether a trailing newline exists.
 *
 * Not currently exploitable — the timestamp sits inside the signature base, so an
 * attacker would need WLCM to have signed the newline-bearing value. Fixed anyway,
 * because the disagreement itself is the defect and the safe form was already in the
 * codebase.
 */
final class TimestampAnchorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Freshness ON. The anchor must reject on its own, not because the parsed instant
        // happens to fall outside a window.
        $app['config']->set('wlcm.webhook.tolerance_seconds', 300);
    }

    /**
     * A trailing LF on each timestamp form the guard accepts. CR, CRLF and a trailing
     * space are already refused — PCRE `$` tolerates exactly one final `\n` and nothing
     * else — so LF is the whole of the hole, and the cases below say so rather than
     * leaving the reader to wonder which whitespace is covered.
     *
     * @return array<string, array{string, bool}>
     */
    public static function trailingBytes(): array
    {
        $cases = [];

        foreach (['1785065886', '1745740800000', '2026-07-24 12:00:00'] as $stamp) {
            foreach (['LF' => "\n", 'CR' => "\r", 'CRLF' => "\r\n", 'space' => ' ', 'tab' => "\t"] as $name => $suffix) {
                $cases["{$stamp} + {$name}"] = [$stamp.$suffix, false];
            }

            $cases["{$stamp} clean"] = [$stamp, true];
        }

        return $cases;
    }

    #[DataProvider('trailingBytes')]
    public function test_only_a_clean_timestamp_matches_the_shape(string $timestamp, bool $accepted): void
    {
        $this->assertSame(
            $accepted,
            preg_match(self::shape(), $timestamp) === 1,
            'the shape guard disagrees with Tiyin about trailing whitespace',
        );
    }

    /**
     * The parser has two anchors of its own, and they are a separate hole: even with the
     * shape guard fixed, `/^\d{10}$/` would still read "1785065886\n" as that epoch.
     */
    #[DataProvider('trailingBytes')]
    public function test_the_parser_refuses_what_the_shape_refuses(string $timestamp, bool $accepted): void
    {
        $parse = new ReflectionMethod(VerifyWebhookSignature::class, 'parseTimestamp');

        $this->assertSame($accepted, $parse->invoke(null, $timestamp) !== null);
    }

    // End to end, so the two guards cannot both be right in isolation and wrong together.
    public function test_a_newline_bearing_timestamp_is_refused_at_the_route(): void
    {
        Event::fake();
        $timestamp = ((string) time())."\n";

        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => $timestamp,
        ];
        // Signed over the newline-bearing value, so the refusal cannot come from the
        // signature check standing in for the anchor.
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$timestamp}", 'whsec_test');

        $this->postJson('/wlcm/webhook', $data)->assertStatus(422);
        Event::assertNotDispatched(WebhookReceived::class);
    }

    private static function shape(): string
    {
        $shape = (new \ReflectionClass(VerifyWebhookSignature::class))->getConstant('TIMESTAMP_SHAPE');
        self::assertIsString($shape);

        return $shape;
    }
}
