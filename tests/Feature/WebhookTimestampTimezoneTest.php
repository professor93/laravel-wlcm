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
 * WLCM sends UTC. A datetime timestamp carrying no offset was resolved in the APP's
 * timezone, so a host at +5 read every delivery as five hours out and 403'd it — and no
 * `tolerance_seconds` a merchant would set can absorb a whole UTC offset. That is a live
 * delivery lost on a config value that has nothing to do with WLCM.
 */
final class WebhookTimestampTimezoneTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // Freshness ON: this whole defect lives inside the freshness window, and the base
        // TestCase disables it.
        $app['config']->set('wlcm.webhook.tolerance_seconds', 300);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function timezones(): array
    {
        return [
            'utc' => ['UTC'],
            'ahead' => ['Asia/Tashkent'],
            'behind' => ['America/Los_Angeles'],
            'half offset' => ['Asia/Kolkata'],
        ];
    }

    /**
     * @return array<string, array{string}>
     *
     * Every timestamp form the shape guard accepts that denotes a real instant. The bare
     * forms are the ones that were broken; the offset-bearing ones are here so a fix that
     * forces UTC onto EVERYTHING cannot pass — that would break them in the other
     * direction, which is the mistake this fix is one step away from.
     */
    public static function stamps(): array
    {
        return [
            'epoch seconds' => ['U'],
            'epoch millis' => ['Uv'],
            'bare space' => ['Y-m-d H:i:s'],
            'bare T' => ['Y-m-d\TH:i:s'],
            'zulu' => ['Y-m-d\TH:i:s\Z'],
            'offset' => ['Y-m-d\TH:i:sP'],
            'fraction and offset' => ['Y-m-d\TH:i:s.vP'],
        ];
    }

    #[DataProvider('timezones')]
    public function test_a_bare_utc_datetime_verifies_under_any_app_timezone(string $timezone): void
    {
        Event::fake();
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        // What WLCM actually puts on the wire: UTC wall-clock, no offset marker.
        $timestamp = gmdate('Y-m-d H:i:s');

        $this->postJson('/wlcm/webhook', $this->signed($timestamp))->assertOk();
        Event::assertDispatched(WebhookReceived::class);
    }

    /**
     * The offset-bearing forms must keep reading their own offset, at every app timezone.
     * A fix that stamps UTC onto a string that already says `+05:00` would silently move
     * those deliveries five hours and lose them the same way.
     */
    #[DataProvider('stamps')]
    public function test_every_accepted_timestamp_form_survives_a_shifted_host(string $format): void
    {
        Event::fake();
        config(['app.timezone' => 'Asia/Tashkent']);
        date_default_timezone_set('Asia/Tashkent');

        $timestamp = gmdate($format) === '' ? '' : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format($format);

        $this->postJson('/wlcm/webhook', $this->signed($timestamp))->assertOk();
        Event::assertDispatched(WebhookReceived::class);
    }

    /**
     * @return array<string, array{string}>
     *
     * Strings DateTimeImmutable happily reads as the current instant. strtotime() refused
     * every one of them, so swapping the parser turned a closed failure into an open one:
     * each of these would be permanently fresh. The route's shape guard blocks them today,
     * which is exactly why this goes at the parser instead — a check that survives only
     * because a neighbour catches the leftovers is not a check.
     */
    public static function alwaysFreshStrings(): array
    {
        return [
            'empty' => [''],
            'now' => ['now'],
            'relative' => ['+1 day'],
            'at sign epoch' => ['@1745740800'],
            'midnight' => ['midnight'],
        ];
    }

    #[DataProvider('alwaysFreshStrings')]
    public function test_a_string_that_would_read_as_the_current_instant_is_refused(string $timestamp): void
    {
        $parse = (new ReflectionMethod(VerifyWebhookSignature::class, 'parseTimestamp'));

        $this->assertNull($parse->invoke(null, $timestamp), 'a non-timestamp parsed as an instant');
    }

    /** @return array<string, string> */
    private function signed(string $timestamp): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => $timestamp,
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$timestamp}", 'whsec_test');

        return $data;
    }
}
