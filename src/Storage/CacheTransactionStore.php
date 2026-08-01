<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Partner\Data\Order;
use Uzbek\Wlcm\Partner\Data\OrderLink;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

/**
 * Webhook dedupe with no table and no migration.
 *
 * The claim is `Cache::add()`, which is atomic on every driver that matters — SETNX on
 * redis, an INSERT on the database driver, a locked write on file. That is the same
 * "the write IS the verdict" primitive the SQL store uses, so concurrent copies of one
 * delivery still produce exactly one winner.
 *
 * WHAT THIS IS WEAKER AT, stated plainly because choosing it is choosing these:
 *
 * 1. NO STATE LADDER. The SQL store only re-dispatches when a payment moves FORWARD, so
 *    a replayed earlier state is refused as stale. Here the key is per (payment_id,
 *    state), so a replayed `state=1` arriving after `state=2` is a different key and
 *    dispatches again. Enforcing order would need a read-then-compare, which is the race
 *    this design exists to avoid.
 * 2. NO AUDIT TRAIL. recordCheckout/recordOrder/recordOrderLink do nothing — there is no
 *    table to write to. `wlcm.storage.logs` is a separate setting and still works.
 * 3. EVICTION LOSES THE MARKER. A cache flush, an LRU eviction under memory pressure, or
 *    a TTL expiry means a later redelivery is treated as fresh and dispatches again.
 *
 * Use an ARRAY cache driver and it protects nothing across requests. Use `redis` or
 * `database`. Far stronger than no dedupe at all; weaker than `transactions => true`.
 */
final readonly class CacheTransactionStore implements TransactionStore
{
    public function __construct(
        private Cache $cache,
        private Repository $config,
    ) {}

    public function applyWebhook(WebhookPayload $payload, string $token): WebhookOutcome
    {
        return $this->claim($this->paymentKey($payload), $token);
    }

    public function markProcessed(WebhookPayload $payload, string $token): void
    {
        $this->markDone($this->paymentKey($payload), $token);
    }

    public function releaseClaim(WebhookPayload $payload, string $token): void
    {
        $this->release($this->paymentKey($payload), $token);
    }

    public function claimSubscription(SubscriptionWebhookPayload $payload, string $token): WebhookOutcome
    {
        return $this->claim($this->subscriptionKey($payload), $token);
    }

    public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload, string $token): void
    {
        $this->markDone($this->subscriptionKey($payload), $token);
    }

    public function releaseSubscriptionClaim(SubscriptionWebhookPayload $payload, string $token): void
    {
        $this->release($this->subscriptionKey($payload), $token);
    }

    /** @param array<string, mixed> $requestPayload */
    public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void
    {
        // No table, no audit. Deliberate and documented, not an oversight.
    }

    /** @param array<string, mixed> $requestPayload */
    public function recordOrder(string $connection, array $requestPayload, Order $order): void
    {
        //
    }

    public function recordOrderLink(string $connection, string $provider, OrderLink $link): void
    {
        //
    }

    /**
     * add() returns false when the key already exists, and that failure IS the verdict —
     * never a read followed by a decision, which is the race the SQL store was rewritten
     * to remove.
     */
    private function claim(string $key, string $token): WebhookOutcome
    {
        if ($this->cache->add($key, $token, $this->leaseSeconds())) {
            return WebhookOutcome::Fresh;
        }

        // Someone got there first. Which of the two it is decides what the loser logs and
        // whether a later redelivery may retry.
        return $this->cache->get($key.':done') !== null
            ? WebhookOutcome::Duplicate
            : WebhookOutcome::InFlight;
    }

    private function markDone(string $key, string $token): void
    {
        // Token-scoped, exactly as the SQL store is: a newer delivery may legitimately
        // own this key by now, and stamping it processed would suppress its redelivery.
        if ($this->cache->get($key) !== $token) {
            return;
        }

        $this->cache->put($key.':done', true, $this->ttlSeconds());
        // Hold the claim for the full retention window rather than releasing it, so a
        // replay finds the key taken and the ':done' marker beside it.
        $this->cache->put($key, $token, $this->ttlSeconds());
    }

    private function release(string $key, string $token): void
    {
        // Only the owner may release. A handler that threw must let the NEXT redelivery
        // through, but must not hand that right to a different delivery's worker.
        if ($this->cache->get($key) === $token) {
            $this->cache->forget($key);
        }
    }

    private function paymentKey(WebhookPayload $payload): string
    {
        $connection = $payload->connection ?? 'main';

        // Per (connection, payment_id, state): a resend of the SAME state is a duplicate,
        // a different state is a different delivery. No ordering — see the class docblock.
        return 'wlcm:dedupe:p:'.sha1($connection.'|'.$payload->paymentId.'|'.$payload->rawState);
    }

    private function subscriptionKey(SubscriptionWebhookPayload $payload): string
    {
        $connection = $payload->connection ?? 'main';

        // Length-prefixed, for the reason the SQL delivery_key is: a bare join lets
        // ("A:B","C") and ("A","B:C") collide and silently swallow one of two distinct
        // deliveries.
        $parts = [$payload->externalOrderId, $payload->event, $payload->timestamp];
        $joined = implode('', array_map(static fn (string $p): string => strlen($p).':'.$p, $parts));

        return 'wlcm:dedupe:s:'.sha1($connection.'|'.$joined);
    }

    private function leaseSeconds(): int
    {
        $lease = $this->config->get('wlcm.webhook.lease_seconds', 60);

        return is_numeric($lease) && (int) $lease > 0 ? (int) $lease : 60;
    }

    private function ttlSeconds(): int
    {
        $ttl = $this->config->get('wlcm.storage.cache_ttl', 86400);

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 86400;
    }
}
