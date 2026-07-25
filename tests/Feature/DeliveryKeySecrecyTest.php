<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookOutcome;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;

// delivery_key sits in the clear next to a scrubbed external_id. If the key is
// unkeyed, the scrub is cosmetic: everything else in the preimage is readable, so
// the six hidden digits fall to a brute force over the hash.
final class DeliveryKeySecrecyTest extends TestCase
{
    use RefreshDatabase;

    private const string PAN = '4111111111111111';

    private const string EVENT = 'subscription.charged';

    private const string TIMESTAMP = '1745740800000';

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('wlcm.storage.transactions', true);
        $app['config']->set('wlcm.connections.other.partner', [
            'base_url' => 'https://api.wlcm.test/api/v1',
            'api_key' => 'pk_test_key',
            'api_secret' => 'sk_test_secret',
            'webhook_secret' => 'whsec_other',
        ]);
    }

    private function payload(string $connection = 'main', ?string $reference = null): SubscriptionWebhookPayload
    {
        return SubscriptionWebhookPayload::fromArray([
            'event' => self::EVENT,
            'external_order_id' => $reference ?? 'sub for card '.self::PAN,
            'external_user_id' => 'user-1001',
            'subscription_id' => 8,
            'amount' => '49900.00',
            'status' => 'active',
            'timestamp' => self::TIMESTAMP,
        ], $connection);
    }

    private function recorder(): TransactionStore
    {
        return app(TransactionStore::class);
    }

    public function test_the_stored_key_does_not_surrender_the_pan_to_a_brute_force(): void
    {
        $this->recorder()->claimSubscription($this->payload());

        $stored = WlcmTransaction::sole()->delivery_key;
        $this->assertIsString($stored);
        // What an attacker with read access already has: 6+4 of the pan, event, timestamp.
        $this->assertSame('sub for card 411111***1111', WlcmTransaction::sole()->external_id);

        // Walk the candidate space the scrub leaves open. The true pan is in this window.
        $matched = null;

        for ($middle = 111105; $middle <= 111115; $middle++) {
            $candidate = 'sub for card 411111'.$middle.'1111';
            $guess = hash('sha256', "{$candidate}:".self::EVENT.':'.self::TIMESTAMP);

            if (hash_equals($stored, $guess)) {
                $matched = $candidate;
            }
        }

        $this->assertNull($matched, 'delivery_key is a brute-forceable oracle for the scrubbed pan');
    }

    public function test_the_key_is_bound_to_the_connection_secret(): void
    {
        $recorder = $this->recorder();
        $recorder->claimSubscription($this->payload('main'));
        $recorder->claimSubscription($this->payload('other'));

        $keys = WlcmTransaction::query()->orderBy('id')->pluck('delivery_key')->all();
        $this->assertCount(2, $keys);
        $this->assertNotEquals($keys[0], $keys[1], 'same preimage under two secrets must not share a key');
    }

    public function test_dedupe_still_works_because_the_key_stays_deterministic(): void
    {
        $recorder = $this->recorder();

        $this->assertSame(WebhookOutcome::Fresh, $recorder->claimSubscription($this->payload()));
        $recorder->markSubscriptionProcessed($this->payload());
        $this->assertNotNull(WlcmTransaction::sole()->processed_at);

        $this->assertSame(WebhookOutcome::Duplicate, $recorder->claimSubscription($this->payload()));
        $this->assertSame(1, WlcmTransaction::query()->count());

        // A different delivery is still a different row.
        $this->assertSame(WebhookOutcome::Fresh, $recorder->claimSubscription($this->payload(reference: 'sub-other')));
        $this->assertSame(2, WlcmTransaction::query()->count());
    }
}
