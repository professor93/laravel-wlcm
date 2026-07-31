<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Tests\TestCase;

final class WebhookEventsCommandTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function delivery(array $overrides = []): array
    {
        // Live shape, verbatim from the events endpoint (2026-08-01).
        return array_merge([
            'id' => 231, 'webhook_id' => 38, 'event_type' => 'payment.success',
            'payload' => ['order_id' => 668], 'status' => 'failed', 'attempts' => 1,
            'response_code' => 503, 'response_body' => '<h1>no tunnel here :(</h1>',
            'error_message' => 'HTTP 503: <h1>no tunnel here :(</h1>',
            'next_retry_at' => null, 'created_at' => '2026-07-30T13:46:40.830425Z',
        ], $overrides);
    }

    /** @param array<string, mixed> ...$events */
    private function fakeEvents(array ...$events): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'count' => count($events), 'next' => null, 'previous' => null, 'results' => $events,
        ], 200)]);
    }

    public function test_it_renders_the_live_delivery_shape(): void
    {
        $this->fakeEvents($this->delivery());

        // One substring per assertion: chained expectsOutputToContain calls do not each
        // match against the whole buffer.
        $this->artisan('wlcm:webhook:events', ['id' => 38])
            ->expectsOutputToContain('payment.success')
            ->assertExitCode(0);
    }

    public function test_it_renders_the_status_and_response_code(): void
    {
        $this->fakeEvents($this->delivery());

        $this->artisan('wlcm:webhook:events', ['id' => 38])
            ->expectsOutputToContain('| failed |')
            ->assertExitCode(0);
    }

    public function test_failed_keeps_a_real_failure(): void
    {
        $this->fakeEvents($this->delivery());

        $this->artisan('wlcm:webhook:events', ['id' => 38, '--failed' => true])
            ->expectsOutputToContain('payment.success')
            ->assertExitCode(0);
    }

    public function test_failed_drops_a_clean_delivery(): void
    {
        $this->fakeEvents($this->delivery([
            'event_type' => 'payment.clean', 'status' => 'delivered',
            'response_code' => 200, 'error_message' => null,
        ]));

        $this->artisan('wlcm:webhook:events', ['id' => 38, '--failed' => true])
            ->expectsOutputToContain('No deliveries match')
            ->assertExitCode(0);
    }

    public function test_status_and_event_filters_are_exact(): void
    {
        $this->fakeEvents(
            $this->delivery(['event_type' => 'payment.success']),
            $this->delivery(['id' => 2, 'event_type' => 'subscription.charged']),
        );

        $this->artisan('wlcm:webhook:events', ['id' => 38, '--event' => 'subscription.charged'])
            ->expectsOutputToContain('subscription.charged')
            ->doesntExpectOutputToContain('payment.success')
            ->assertExitCode(0);
    }

    public function test_since_accepts_relative_shorthand(): void
    {
        $this->fakeEvents(
            $this->delivery(['event_type' => 'old.one', 'created_at' => '2020-01-01T00:00:00Z']),
            $this->delivery(['id' => 2, 'event_type' => 'new.one', 'created_at' => now()->toIso8601String()]),
        );

        $this->artisan('wlcm:webhook:events', ['id' => 38, '--since' => '2h'])
            ->expectsOutputToContain('new.one')
            ->doesntExpectOutputToContain('old.one')
            ->assertExitCode(0);
    }

    public function test_an_unparseable_since_is_a_usage_error(): void
    {
        Http::fake();

        $this->artisan('wlcm:webhook:events', ['id' => 38, '--since' => 'nonsense'])
            ->assertExitCode(2);
    }

    public function test_it_never_truncates_silently(): void
    {
        $this->fakeEvents(
            $this->delivery(['id' => 1]),
            $this->delivery(['id' => 2]),
            $this->delivery(['id' => 3]),
        );

        // A partial view that looks complete is how an operator concludes a delivery
        // never happened.
        $this->artisan('wlcm:webhook:events', ['id' => 38, '--limit' => 1])
            ->expectsOutputToContain('Showing 1 of 3')
            ->assertExitCode(0);
    }

    public function test_a_null_response_code_is_not_a_blank_cell(): void
    {
        // No HTTP exchange happened at all — a different failure from a bad status.
        $this->fakeEvents($this->delivery([
            'response_code' => null,
            'error_message' => "Request error: Request URL is missing an 'http://' or 'https://' protocol.",
        ]));

        $this->artisan('wlcm:webhook:events', ['id' => 38])
            ->expectsOutputToContain('—')
            ->assertExitCode(0);
    }

    public function test_json_emits_the_raw_records(): void
    {
        $this->fakeEvents($this->delivery());

        $this->artisan('wlcm:webhook:events', ['id' => 38, '--json' => true])
            ->expectsOutputToContain('"response_body"')
            ->assertExitCode(0);
    }
}
