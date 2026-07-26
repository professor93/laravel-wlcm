<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;
use Uzbek\Wlcm\Enums\PaymentState;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Exceptions\WebhookClaimException;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Support\ConfigToggle;
use Uzbek\Wlcm\Support\ConnectionConfig;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class TransactionRecorder implements TransactionStore
{
    /**
     * Five, not three, on measurement rather than taste. With the backoff in place three
     * attempts left mysql at 15.9% contended-out on a mixed-unmodelled burst (down from
     * 28.7% with no backoff at all). Extra attempts only ever cost latency on a request
     * that is ALREADY contended — the one that would otherwise 500 — because an
     * uncontended claim never waits at all. The mechanism is also N-sensitive: every
     * worker's compare-and-swap is invalidated whenever another moves the row, so the
     * headroom matters more at higher concurrency than the measured burst shows.
     */
    private const int CLAIM_ATTEMPTS = 5;

    // Settled rungs: Success and CancelledDuringInit sit at 2, Cancelled at 3.
    private const int TERMINAL_RUNG = 2;

    public function __construct(private readonly Repository $config) {}

    private function enabled(): bool
    {
        return ConfigToggle::enabled($this->config->get('wlcm.storage.transactions', false), 'wlcm.storage.transactions');
    }

    // Validated on every claim, never only on the contended branch: a typo must die on
    // delivery one, not during the storm it was added to survive. No null disable —
    // "no lease" means either no exclusion (the bug) or a crashed row stuck forever.
    private function leaseSeconds(): int
    {
        $seconds = $this->config->get('wlcm.webhook.lease_seconds', 60);

        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            throw MissingConfigurationException::forKey('wlcm.webhook.lease_seconds');
        }

        return (int) $seconds;
    }

    /**
     * A live claim: unprocessed, held, and stamped inside the window.
     *
     * Both bounds are deliberately slack by one lease, because timestamps here are
     * second-resolution on the column AND on the binding (Grammar::getDateFormat drops
     * the fraction in php, so every engine truncates alike). Two workers milliseconds
     * apart can therefore land on opposite sides of a second boundary:
     *
     * - $horizon, not $now: a claim stamped a tick after the reader's own snapshot is
     *   live, not bogus. Comparing against $now let the loser call the winner's claim
     *   future-dated and steal it — two dispatches. Only a claim more than a whole lease
     *   ahead can be real clock skew, and that one must still be reclaimable or a
     *   skewed host strands the row forever.
     * - strict <, not <=: truncation floors the stored stamp, so <= could call a claim
     *   expired up to a second early — the harmful direction, a live handler dispatched
     *   twice. Strict < guarantees a full lease elapsed and pushes the rounding into the
     *   benign direction, delaying recovery of a dead claim by at most one second.
     *
     * @param  Builder<WlcmTransaction>  $query
     * @return Builder<WlcmTransaction>
     */
    private static function deadClaim(Builder $query, Carbon $cutoff, Carbon $horizon): Builder
    {
        return $query->where(fn (Builder $dead): Builder => $dead
            ->whereNull('dispatch_token')
            ->orWhereNull('claimed_at')
            ->orWhere('claimed_at', '<', $cutoff)
            ->orWhere('claimed_at', '>', $horizon));
    }

    /**
     * Jittered pause between claim attempts. Three attempts with no pause span well under
     * a millisecond, which is shorter than the single round trip a competing claim needs
     * to commit — so the budget was spent without anyone making progress. Two of these
     * waits give the retry 10-50ms, one to two orders of magnitude more than that round
     * trip. Jitter so a burst does not re-collide in lockstep.
     */
    private static function backoff(int $attempt): void
    {
        if ($attempt < self::CLAIM_ATTEMPTS) {
            usleep(random_int(5_000, 25_000));
        }
    }

    // Deadlock, serialization failure, sqlite busy. Same set a retrying transaction uses.
    private static function contended(QueryException $e): bool
    {
        return (new ConcurrencyErrorDetector)->causedByConcurrencyError($e);
    }

    /** @param array<string, mixed> $requestPayload */
    public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void
    {
        if (! $this->enabled()) {
            return;
        }

        $amount = $requestPayload['amount'] ?? 0;
        $provider = $requestPayload['payment_provider'] ?? null;
        /**
         * @var WlcmJsonScalar $amount
         * @var WlcmJsonScalar $provider
         */
        try {
            WlcmTransaction::create(ColumnFit::row([
                'connection' => $connection,
                'api' => 'partner',
                'external_id' => self::scrubbed($response->externalId),
                'order_id' => (string) $response->orderId,
                'transaction_id' => $response->transactionId,
                'amount_tiyin' => (int) $amount,
                'provider' => $provider === null ? null : self::scrubbed((string) $provider),
                'state' => $response->state,
                'state_text' => $response->stateEnum()?->name,
                'meta' => Masker::scrubProse(Masker::mask(['checkout_url' => $response->checkoutUrl, 'cid' => $response->cid])),
            ]));
        } catch (Throwable $e) {
            report($e); // Audit write must never break payment call.
        }
    }

    // No transaction and no lockForUpdate anywhere below. Each statement is atomic on
    // its own; the transaction only ever bought gap-lock deadlocks and, on pgsql, an
    // aborted transaction we could not read out of.
    public function applyWebhook(WebhookPayload $payload, string $token): WebhookOutcome
    {
        if (! $this->enabled()) {
            return WebhookOutcome::Fresh;
        }

        $lease = $this->leaseSeconds();
        $connection = ColumnFit::value('connection', $payload->connection);
        $paymentId = ColumnFit::value('payment_id', $payload->paymentId);

        for ($attempt = 1; $attempt <= self::CLAIM_ATTEMPTS; $attempt++) {
            try {
                $outcome = $this->claimPayment($payload, $token, $connection, $paymentId, $lease);

                if ($outcome !== null) {
                    return $outcome;
                }

                // The row moved under us. Wait like the contended branch does: with no
                // pause all three attempts burn inside one sub-millisecond window, the
                // writer we are losing to never gets to commit, and a delivery 500s on a
                // race it would have won a few milliseconds later.
                self::backoff($attempt);
            } catch (UniqueConstraintViolationException $e) {
                // Lost an insert race. Caught BEFORE QueryException on purpose: bindings
                // are interpolated into the message the concurrency detector reads, so a
                // merchant id spelling "database is locked" must never route here.
                if ($attempt === self::CLAIM_ATTEMPTS) {
                    throw $e;
                }
            } catch (QueryException $e) {
                if ($attempt === self::CLAIM_ATTEMPTS || ! self::contended($e)) {
                    throw $e;
                }

                self::backoff($attempt);
            }
        }

        // Fail closed and loud. A 500 is a delivery someone can be asked to replay; a
        // guessed Duplicate is a payment event that never fires.
        throw WebhookClaimException::contended('payment');
    }

    // null = the row moved under us; the caller re-reads and re-decides.
    private function claimPayment(
        WebhookPayload $payload,
        string $token,
        ?string $connection,
        ?string $paymentId,
        int $lease,
    ): ?WebhookOutcome {
        $now = now();
        $cutoff = $now->copy()->subSeconds($lease);
        $horizon = $now->copy()->addSeconds($lease);

        // Every read below classifies, and a classification off a lagging replica is a
        // lost dispatch: DB::transaction and lockForUpdate used to force these onto the
        // write pdo for free, and both are gone. The CAS statements are immune — an
        // UPDATE predicate always evaluates on the primary — so only the reads need it.
        // No cost on a single-host database.
        $row = WlcmTransaction::query()
            ->useWritePdo()
            ->where('connection', $connection)
            ->where('payment_id', $paymentId)
            ->first();

        if ($row !== null) {
            if ($row->state === $payload->state) {
                // Same delivery. Only a claim that is finished or provably dead may be
                // retaken. Reading processed_at to decide is the line that dispatched ten
                // times; the affected-row count decides instead.
                $taken = self::deadClaim(
                    WlcmTransaction::query()
                        ->whereKey($row->getKey())
                        ->where('state', $payload->state)
                        ->whereNull('processed_at'),
                    $cutoff,
                    $horizon,
                )->update(self::claimColumns($token, $now));

                return $taken === 1
                    ? WebhookOutcome::Fresh
                    : $this->verdict($row->getKey(), $token, $payload->state);
            }

            // A different state is a different logical delivery, but the ladder runs one
            // way. A backward move is a stale replay, and letting one through lets two
            // states ping-pong the row and re-dispatch whatever is still in flight on it.
            if (! self::advances($row->state, $payload->state)) {
                return WebhookOutcome::Stale;
            }

            $moved = WlcmTransaction::query()
                ->whereKey($row->getKey())
                ->where('state', $row->state) // CAS: only off the state we actually read.
                ->update([...$this->payloadColumns($payload), ...self::claimColumns($token, $now)]);

            return $moved === 1 ? WebhookOutcome::Fresh : null;
        }

        // No row for this payment_id. Adopt this order's checkout row if one is free.
        // Read the id first: UPDATE ... LIMIT is mysql-only.
        $checkoutId = WlcmTransaction::query()
            ->useWritePdo()
            ->where('connection', $connection)
            ->where('order_id', ColumnFit::value('order_id', $payload->orderId))
            ->whereNull('payment_id')
            ->value('id');

        if ($checkoutId !== null) {
            $adopted = WlcmTransaction::query()
                ->whereKey($checkoutId)
                ->whereNull('payment_id') // Re-asserted: the read above took no lock.
                ->update([...$this->payloadColumns($payload), ...self::claimColumns($token, $now)]);

            return $adopted === 1 ? WebhookOutcome::Fresh : null;
        }

        WlcmTransaction::query()->create([
            'connection' => $connection,
            ...$this->payloadColumns($payload),
            ...self::claimColumns($token, $now),
        ]);

        return WebhookOutcome::Fresh;
    }

    private function verdict(mixed $id, string $token, int $state): ?WebhookOutcome
    {
        $row = WlcmTransaction::query()->useWritePdo()->whereKey($id)->first();

        if ($row === null) {
            return null; // Pruned under us. Re-run: the insert path takes it.
        }

        // Our own token: the write landed and only the client's view of it was lost.
        // Laravel silently re-runs a statement whose connection died mid-flight (it only
        // skips that inside a transaction, and there is none here), and the re-run reads
        // our committed claim and reports zero rows.
        if ($row->getAttribute('dispatch_token') === $token) {
            return WebhookOutcome::Fresh;
        }

        if ($row->state !== $state) {
            return null; // Moved on; re-decide against the state it actually holds.
        }

        return $row->processed_at !== null ? WebhookOutcome::Duplicate : WebhookOutcome::InFlight;
    }

    /**
     * WLCM states are a one-way ladder. A state we do not model has no rung and is
     * admitted rather than refused — the ladder is our model of WLCM's, not WLCM's, and
     * WLCM has been seen emitting states its own docs omit.
     *
     * It must not be a way back OUT of a settled row, though. Admitting one from a
     * terminal rung parked the row on the unmodelled state, from which every modelled
     * state read as an advance — so a replay of the success re-dispatched the money
     * event. "Never refused" loses to "a terminal row accepts only a cancel" here.
     */
    private static function advances(?int $from, int $to): bool
    {
        return self::rung($to) < 0
            ? self::rung($from) < self::TERMINAL_RUNG
            : self::rung($to) > self::rung($from);
    }

    // The -1 default means "no rung", not CancelledDuringInit — that state is a real
    // value of -1 but matches its own arm above and lands on rung 2.
    private static function rung(?int $state): int
    {
        return match ($state) {
            PaymentState::Created->value => 0,
            PaymentState::Initiating->value => 1,
            PaymentState::Success->value, PaymentState::CancelledDuringInit->value => 2,
            PaymentState::Cancelled->value => 3,
            default => -1,
        };
    }

    /** @return array<string, mixed> */
    private function payloadColumns(WebhookPayload $payload): array
    {
        return ColumnFit::row([
            'api' => 'partner',
            'external_id' => self::scrubbed($payload->externalId),
            'order_id' => $payload->orderId,
            'payment_id' => $payload->paymentId,
            'amount_tiyin' => $payload->amountTiyin(),
            'provider' => self::scrubbed($payload->provider),
            'state' => $payload->state,
            'state_text' => $payload->stateEnum()?->name,
        ]);
    }

    public function markProcessed(WebhookPayload $payload, string $token): void
    {
        if (! $this->enabled()) {
            return;
        }

        WlcmTransaction::query()
            ->where('connection', ColumnFit::value('connection', $payload->connection))
            ->where('payment_id', ColumnFit::value('payment_id', $payload->paymentId))
            // Own claim only. A later transition may already hold this row, and stamping
            // ITS claim processed swallows that delivery's redelivery for good.
            ->where('dispatch_token', $token)
            ->update(['processed_at' => now(), 'dispatch_token' => null, 'claimed_at' => null]);
    }

    // Release, not mark: the dispatch died, so the next redelivery must run it again —
    // with no lease wait. Token-scoped, or a late release clears a live claim and lets a
    // third copy in.
    public function releaseClaim(WebhookPayload $payload, string $token): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->release(fn (): Builder => WlcmTransaction::query()
            ->where('connection', ColumnFit::value('connection', $payload->connection))
            ->where('payment_id', ColumnFit::value('payment_id', $payload->paymentId)), $token);
    }

    public function claimSubscription(SubscriptionWebhookPayload $payload, string $token): WebhookOutcome
    {
        if (! $this->enabled()) {
            return WebhookOutcome::Fresh;
        }

        $lease = $this->leaseSeconds();
        $key = $this->subscriptionKey($payload);
        $connection = ColumnFit::value('connection', $payload->connection);

        for ($attempt = 1; $attempt <= self::CLAIM_ATTEMPTS; $attempt++) {
            $now = now();
            $cutoff = $now->copy()->subSeconds($lease);
            $horizon = $now->copy()->addSeconds($lease);

            try {
                WlcmTransaction::query()->create(ColumnFit::row([
                    'connection' => $payload->connection,
                    'api' => 'subscription',
                    'external_id' => self::scrubbed($payload->externalOrderId),
                    'state_text' => self::scrubbed($payload->event),
                    // Keyed hmac over the raw values: unforgeable without the secret, and
                    // scrubbing the preimage would only collide keys without hiding anything.
                    'delivery_key' => $key,
                    ...self::claimColumns($token, $now),
                ]));

                return WebhookOutcome::Fresh;
            } catch (UniqueConstraintViolationException $e) {
                // Lost the unique(connection, delivery_key) election. That election is a
                // perfect single winner on all three engines; the old catch threw it away
                // by re-resolving every loser on processed_at.
                $taken = self::deadClaim(
                    WlcmTransaction::query()
                        ->where('connection', $connection)
                        ->where('delivery_key', $key)
                        ->whereNull('processed_at'),
                    $cutoff,
                    $horizon,
                )->update(['dispatch_token' => $token, 'claimed_at' => $now]);

                if ($taken === 1) {
                    return WebhookOutcome::Fresh;
                }

                $existing = WlcmTransaction::query()
                    ->useWritePdo()
                    ->where('connection', $connection)
                    ->where('delivery_key', $key)
                    ->first();

                if ($existing === null) {
                    if ($attempt === self::CLAIM_ATTEMPTS) {
                        throw $e;
                    }

                    self::backoff($attempt);

                    continue; // Pruned under us; the insert can have it.
                }

                // Our own row: the insert committed and the reply was lost, so Laravel
                // re-ran it against our own unique key.
                if ($existing->getAttribute('dispatch_token') === $token) {
                    return WebhookOutcome::Fresh;
                }

                return $existing->processed_at !== null
                    ? WebhookOutcome::Duplicate
                    : WebhookOutcome::InFlight;
            } catch (QueryException $e) {
                if ($attempt === self::CLAIM_ATTEMPTS || ! self::contended($e)) {
                    throw $e;
                }

                self::backoff($attempt);
            }
        }

        throw WebhookClaimException::contended('subscription');
    }

    public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload, string $token): void
    {
        if (! $this->enabled()) {
            return;
        }

        WlcmTransaction::query()
            ->where('connection', ColumnFit::value('connection', $payload->connection))
            ->where('delivery_key', $this->subscriptionKey($payload))
            ->where('dispatch_token', $token)
            ->update(['processed_at' => now(), 'dispatch_token' => null, 'claimed_at' => null]);
    }

    public function releaseSubscriptionClaim(SubscriptionWebhookPayload $payload, string $token): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->release(fn (): Builder => WlcmTransaction::query()
            ->where('connection', ColumnFit::value('connection', $payload->connection))
            ->where('delivery_key', $this->subscriptionKey($payload)), $token);
    }

    /**
     * Takes a factory, not a Builder: building the query here rather than in the caller's
     * argument list is what makes the promise below true. subscriptionKey() reaches for
     * the connection secret and can throw, and that throw would have escaped this try and
     * replaced the handler's own.
     *
     * @param  callable(): Builder<WlcmTransaction>  $query
     */
    private function release(callable $query, string $token): void
    {
        try {
            $query()->where('dispatch_token', $token)
                ->whereNull('processed_at')
                ->update(['dispatch_token' => null, 'claimed_at' => null]);
        } catch (Throwable $e) {
            // Runs with the handler's throwable in flight and must never replace it.
            // Swallowing costs only the lease: the claim still expires. report() itself
            // can throw when the app's log channel is down, hence the bare inner catch.
            try {
                report($e);
            } catch (Throwable) {
                //
            }
        }
    }

    // Keyed, not bare. Event and timestamp sit readable in the same delivery and the
    // reference column keeps only 6+4 of a pan, so a bare hash hands the rest back to
    // anyone who can read the row and count to a million.
    private function subscriptionKey(SubscriptionWebhookPayload $payload): string
    {
        return hash_hmac(
            'sha256',
            "{$payload->externalOrderId}:{$payload->event}:{$payload->timestamp}",
            $this->webhookSecret($payload->connection),
        );
    }

    // Same lookup VerifyWebhookSignature already ran for this delivery, so on the webhook
    // path the secret is guaranteed present. Missing = throw; a bare-hash fallback would
    // quietly rebuild the oracle.
    private function webhookSecret(?string $connection): string
    {
        $default = $this->config->get('wlcm.default', 'main');
        /** @var WlcmJsonScalar $default */
        $connection ??= (string) $default;
        $block = ConnectionConfig::block($this->config, $connection);
        $secret = $block === null ? null : Arr::get($block, 'partner.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw MissingConfigurationException::forKey("wlcm.connections.{$connection}.partner.webhook_secret");
        }

        return $secret;
    }

    // Only merchant free-text can hide a pan, and wlcm_logs scrubs its copy of the same
    // field. Server-minted ids are never scrubbed: the scrub is lossy, and two real ids
    // can collapse onto one stored value.
    private static function scrubbed(?string $value): ?string
    {
        return $value === null ? null : Masker::scrubPans($value);
    }

    /**
     * The random token is what guarantees a matched row is a CHANGED row. Mysql counts
     * changed rows, and a 0 reads as "someone else owns the dispatch" — an event that
     * never fires. Never make this deterministic, boolean, or derived from a request id.
     *
     * @return array<string, mixed>
     */
    private static function claimColumns(string $token, Carbon $now): array
    {
        return ['dispatch_token' => $token, 'claimed_at' => $now, 'processed_at' => null];
    }
}
