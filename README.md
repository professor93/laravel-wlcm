# laravel-wlcm

WLCM payment client for Laravel. Partner API (HMAC), webhooks, subscriptions, optional audit tables.

## Install

```bash
composer require uzbek/laravel-wlcm
php artisan vendor:publish --tag=wlcm-config
```

## Configure

```
WLCM_BASE_URL=          # includes /api/v1
WLCM_API_KEY=
WLCM_API_SECRET=
WLCM_WEBHOOK_SECRET=
```

Multiple partners: entries under `wlcm.connections` in `config/wlcm.php`, then `Wlcm::connection('name')->partner()->...` where examples below say `Wlcm::partner()->...` — shorthand for the `wlcm.default` connection.

## Money

All amounts `int` tiyin (1 sum = 100 tiyin) — params, responses, everything you pass. Package converts to sum where the API wants sum (subscription create/charge, fixed splits by default — see `split_amount_unit`). Inbound SUM values stay raw on the DTO; `amountTiyin()` uses digit math, never float. Non-positive amounts throw `InvalidAmountException`: `checkout()->create()` rejects `amountTiyin <= 0`. Subscription `create`/`charge` convert through `Tiyin::toSum`, which also demands divisibility by 100 — `4990000` ships as `49900`, `4990001` throws. Fixed split amounts must be a real `int`: a string or float throws `InvalidAmountException` in both unit modes, before any conversion — a numeric string would ship the wrong unit silently. Inbound SUM strings are validated, never silently zeroed: `amountTiyin()` throws `InvalidAmountException` on anything but digits with at most two decimal places (optional leading `-`), and on a whole part of 17+ digits.

`stateEnum()` returns `?PaymentState` — check for null before matching on it. Null-honest on `OrderStatus` and `CheckoutResponse`: a non-numeric state, or one missing everywhere the DTO looks (for `OrderStatus`, both the top level and the `status_explanation` fallback), gives `null`. `WebhookPayload` is not — it casts state with `(int)`, so an empty or non-numeric value reads as `Created` (0) and fires no `PaymentSucceeded`/`PaymentCancelled`, a leading-digit string like `"2abc"` reads as `Success`, and only an unmodelled number (say `7`) yields null. A payment delivery whose `state` is missing, or empty (Laravel converts `''` to `null`), never reaches the DTO — 422 at verification.

## Partner API

Redirect checkout:

```php
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Enums\PaymentProvider;

$r = Wlcm::partner()->checkout()->create(
    amountTiyin: 120000,
    returnUrl: 'https://merchant.example.com/payment/return',
    externalId: 'ORD-100045',
    provider: PaymentProvider::Payme,
);

$r->checkoutUrl; // redirect the customer here
```

Card checkout: `provider: 'card'` + `cardNumber` + `expireDate`, then `Wlcm::partner()->payments()->confirmOtp($transactionId, $cid, $otp)`. Other calls:

- `Wlcm::partner()->partners()->me()` — partner info
- `Wlcm::partner()->providers()->all()` — payment providers (public endpoint), `providerEnum()` per row
- `Wlcm::partner()->orders()->status($id)` — `isPaid`, `stateEnum()`, `gatewayEnum()`, `amount` (raw SUM float), `amountTiyin()` (public endpoint)
- `Wlcm::partner()->payments()->cancel($id, reason: 1)` — `reason` = int code
- `Wlcm::partner()->payments()->confirmPaylov($transactionId, $otp)` — UNVERIFIED: camelCase body from sandbox console, not confirmed live
- `Wlcm::partner()->webhooks()` — `create($url, $secret)`, `all()`, `update()`, `events()` (delivery history), `delete()`. `secret_key` chosen at create goes into `WLCM_WEBHOOK_SECRET`

`PaymentProvider` values are the lower-case strings the API accepts on the way out, but it answers with display casing (`Payme`, `Click`, `Uzum`), so `tryFrom()` misses every live row. Parse inbound names with `PaymentProvider::fromName()` — case-insensitive, `null` on anything with no case, including the sandbox-only `mock_payme`. `PaymentProviderInfo::providerEnum()`, `OrderStatus::gatewayEnum()` and `WebhookPayload::providerEnum()` already go through it; the raw string stays on the DTO either way.

Error bodies come in three `detail` shapes and all three are read: a `{message, code, suggestions}` object (`getMessage()` from `message`, `errorCode()` from `code`), a plain string (`getMessage()`, plus `errorCode()` when it is bare snake_case), and pydantic's validation list (`getMessage()` from the first entry, field map on `errors()`). A top-level `code` outranks anything in `detail`. `errorCode()` hands back exactly what the server sent, so live UPPER_CASE codes like `ORDER_NOT_FOUND` arrive intact — `errorCodeEnum()` has no case for those and returns null, so match on `errorCode()` unless you know the code is in `WlcmErrorCode`.

## Subscriptions

Recurring charge on bound card. Partner calls HMAC-signed; card attach/confirm/activate = unsigned end-user calls. Lifecycle: `pending → card_attached → active → cancelled/expired`, plus `paused` after 3 failed charges. `cancel()` returns updated `Subscription`.

```php
// Partner creates the subscription — amount in tiyin, converted to sum on the wire
$sub = Wlcm::partner()->subscriptions()->create(
    externalOrderId: 'order-1001',
    externalUserId: 'user-1001',
    amountTiyin: 4990000,
    returnUrl: 'https://app.example.com/return',
);

// User attaches and confirms a card, then activates
$card = Wlcm::partner()->subscriptions()->attachCard($sub->id, $cardNumber, $expireDate, $phone);
Wlcm::partner()->subscriptions()->confirmCard($sub->id, $otp, $card->cardId);
Wlcm::partner()->subscriptions()->activate($sub->id);

// Partner charges later
$charge = Wlcm::partner()->subscriptions()->charge($sub->id, 4990000, $card->cardId);

if (! $charge->succeeded()) {
    // handle the failed charge
}

Wlcm::partner()->subscriptions()->get($sub->id);
Wlcm::partner()->subscriptions()->findByExternalOrderId('order-1001');
Wlcm::partner()->subscriptions()->cancel($sub->id);
```

**A charge that fails does NOT throw.** The server answers HTTP 200 with `status: "failed"` either way — always check `$charge->succeeded()` before fulfilling.

## Split

```php
$split = Wlcm::partner()->payments()->split(7788, [
    ['recipient_id' => 10, 'recipient_type' => 'brand', 'split_type' => 'percent', 'amount' => 70],
    ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => 100000], // tiyin
]);

Wlcm::partner()->payments()->getSplit(7788);
```

Fixed amounts int tiyin like everything else — non-int throws. Wire unit via `wlcm.connections.{connection}.partner.split_amount_unit`: default `'sum'` converts for you, `'tiyin'` passes raw. Unit is sandbox-proven SUM (server errors compare fixed totals against payment SUM value); WLCM docs claim tiyin. Final WLCM confirmation pending (2026-07-25).

> **Warning:** a wrong `split_amount_unit` misplaces money by a factor of 100. Do not flip this config without WLCM's confirmation.

Server rules (WLCM-enforced): fixed total must equal payment amount exactly; duplicate recipients rejected 422; unknown recipients 404. Percent values (0-100) never converted. Response amounts stay raw decimal SUM strings on the DTO. Response rows that are not arrays get dropped from `$split->splits` instead of hydrated into zeroed recipients — compare `count($split->splits)` against `$split->totalRecipients` to detect it; `$split->raw` keeps the untouched body either way.

## Fiscalization

```php
$fiscal = Wlcm::partner()->fiscalization()->get(paymentId: 7788);

$registered = Wlcm::partner()->fiscalization()->register(
    items: [['title' => 'Premium subscription', 'price' => 120000, 'count' => 1, 'vat_percent' => 12]],
    paymentId: 7788,
);

$refunded = Wlcm::partner()->fiscalization()->refund(paymentId: 7788);
```

Item amounts passthrough — SDK never converts them. Docs say tiyin; caller sends what the API expects. (Sandbox OFD unwired, register returns 500.)

## Webhooks

Route auto-registered `POST /wlcm/webhook/{connection?}` (path via `wlcm.webhook.path`), unless `wlcm.webhook.enabled` says otherwise: `true`/`'1'`/`1` registers it, `false`/`'0'`/`0`/`null`/`''` does not, and anything else — `'off'`, `'no'`, `'disabled'` — throws `MissingConfigurationException` rather than leave a public endpoint serving on a value the package cannot read. The toggle is enforced **twice**, and the second one is the one that matters: routes are not registered at boot when it is off, *and* every delivery re-reads it and 404s if it is off. Registration alone was not enough — `php artisan route:cache` bakes the table while the toggle is on, after which boot never runs that check again, and a process reporting `enabled: false` kept accepting signed deliveries and firing events until someone ran `route:clear`. An unreadable value throws on the delivery rather than at boot in that same cached case. The `{connection}` segment is constrained to the keys of `wlcm.connections` as read when routes load — an unknown name 404s, and a connection added to config after boot does not widen the list. Signature verified against `wlcm.connections.{connection}.partner.webhook_secret` before your code runs; invalid = 403, no listener fires. It is computed over the RAW request body, not `$request->input()`: a stock app's `TrimStrings` and `ConvertEmptyStringsToNull` run first, and verifying against values the framework rewrote refused deliveries WLCM had signed correctly. That applies when the request is DECLARED as JSON; anything else falls back to the framework-parsed input and is still verified against rewritten values — a known residual, left alone because refusing a non-JSON body on no evidence would invent an outage. The test is the declared encoding, not whether the body happens to parse: a JSON body sent as `text/plain` parses perfectly, and treating that as JSON once let the query string supply the dispatched values while the body supplied the signature. Whatever the encoding, the base, the guards, the freshness check, the dispatched payload and the logged blob all come from one document. Two further refusals guard the shared signature format — a colon in either `event` or `external_order_id` is 422 (both bases are bare colon joins, so a captured payment delivery could otherwise replay as a subscription with its signature untouched; a colon in `event` or `external_order_id`, a colon in `order_id`/`payment_id`, a non-numeric `state`, and a `timestamp` that is not a plain epoch or ISO-style datetime are each refused; together they leave no accepted split of a captured base in either direction, and both directions are verified by tests that GENERATE every split rather than by argument — three hand-written completeness proofs of this guard were wrong before those tests existed, and a fourth version of the reverse generator was wrong again for assuming a merchant reference cannot contain a colon, which our guard only enforces on delivery and never stopped WLCM signing. The generated sets vary every field an attacker controls — the timestamp across all six accepted shapes, and the reference across one to four colon-separated parts, numeric and not. One variable is held on purpose and is the named limit of the claim: the `event` is taken as colon-free, because WLCM mints it from `subscription.` names that contain none). **A subscription `external_order_id` may not contain a colon, and a payment `state` must be numeric.** These are shape restrictions, not a proof: they close every reading enumerable from a base WLCM would actually sign, but two bare colon joins with no domain tag remain ambiguous in principle. The proper fix is domain separation or length-prefixing in both signature bases, which is WLCM's wire format to change, and on the PAYMENT family a negative or unparseable `amount` is 422 — it rides outside the base, a negative stores as negative money on Postgres while MySQL rejects the write, and an unparseable one used to throw inside the claim and 500 with no audit row at all. Subscriptions are not amount-checked here: nothing stores their amount, so the same value cannot reach a money column. Missing, empty or non-scalar *required* field = 422 — payments need `order_id`, `payment_id`, `amount`, `state`, `timestamp`; subscriptions need `external_order_id`, `event`, `timestamp`. Optional fields are never checked and coerce to a typed default — `''` on payments, `''`/`0`/`'0'` on subscriptions. A field sent as `""` is treated as absent either way, but by different routes: on the raw-body path the package itself treats empty as absent, and on the non-JSON fallback a stock app's `ConvertEmptyStringsToNull` has already converted it. One shared predicate decides it, so the router and the audit trail cannot disagree; `signature` is required too, but checked on its own rather than through either list. Secret absent from config = `MissingConfigurationException`. Signature base is `{order_id}:{payment_id}:{state}:{timestamp}` for payments, `{external_order_id}:{event}:{timestamp}` for subscriptions.

> **`amount` and `external_id` are in neither base.** They arrive unauthenticated — a valid signature says nothing about them. Never fulfill on the webhook's amount; call `Wlcm::partner()->orders()->status($orderId)` and settle from that.

**A host route at the same method and URI silently replaces ours**, taking `VerifyWebhookSignature` out of the chain entirely — so merchant code runs on an unverified body, and `route:list` is the only symptom. The package watches for this once the route table is final and `report()`s a `WebhookRouteConflictException` naming the URI and the three ways out. It reports rather than throws on purpose: a boot-time throw would take down `route:list`, the one tool that shows you what happened. What it checks is whether whoever serves that URI carries the signature middleware, not whether the route is ours — so re-registering the path yourself *with* `VerifyWebhookSignature` attached is recognised as deliberate and stays quiet. **Limitation worth knowing: the check needs our own route present to learn the URI, so if you `route:cache` a table that already contains the collision, there is nothing to compare against and the conflict is not reported.** Run `route:list | grep wlcm` once after any change to your route files, and do it before caching rather than after.

**Deploy caches change what this package sees, and one case is a security trap.** The package reads config at request time, never `env()` — so the webhook secret, the toggles and the tolerance window all take effect the moment the *config* does. But `php artisan config:cache` freezes `env()` into `bootstrap/cache/config.php`, so **rotating a webhook secret in `.env` alone is inert**: the old baked secret keeps verifying and dispatching while the new one 403s. A rotation that looks done is not done. Re-run `config:cache` (or clear it) as part of the rotation, and verify by sending a delivery signed with the old secret and confirming a 403. `route:cache` compounds it, because it reads the *cached* config — rebuilding routes without rebuilding the config bakes the stale `wlcm.webhook.path`. `wlcm.webhook.enabled` is safe under both, because it is re-read on every delivery rather than only at route registration.

Freshness: `wlcm.webhook.tolerance_seconds` (default 300) 403s a delivery whose `timestamp` sits further than that from now, in either direction. A datetime timestamp that carries no offset is read as **UTC**, which is what WLCM sends — never in your `APP_TIMEZONE`, which used to put a host at +5 five hours outside any tolerance a merchant would set and 403 every delivery it received. Forms that carry their own offset (`Z`, `+05:00`) keep it. A timestamp that is not a plain epoch (10 or 13 digits) or an ISO-style datetime is refused earlier and differently — 422 `Invalid field: timestamp`, whether or not freshness is enabled — because it is malformed rather than stale, and because an unconstrained trailing field is what allows one signature base to be re-read as the other. `null` is the only way to switch the check off — any other non-numeric or non-positive value throws `MissingConfigurationException`. Freshness is not dedupe: an identical delivery replayed inside the window verifies fine. `wlcm.webhook.lease_seconds` (default 60) is how long a claimed delivery counts as in flight. Unlike `tolerance_seconds` there is **no `null` disable** — `null`, non-numeric or non-positive throws `MissingConfigurationException` on the first delivery. Set it above your slowest synchronous handler and at or below the request timeout that would kill it; 60 is chosen because php-fpm usually kills a request at 30-60s, so an expired lease means that request is definitely dead. Both ends of that window carry one lease of slack, because these timestamps are second-resolution: a claim stamped up to a lease in the future is treated as live — two workers milliseconds apart can otherwise land either side of a second boundary and each conclude the other's claim is bogus — and a claim is only reclaimed once a full lease has provably elapsed. A host running up to a lease fast can hold a row for as long as two leases; one running further ahead than that has its claim treated as dead and reclaimed immediately. The package assumes an NTP-synced fleet, exactly as `tolerance_seconds` already does. The two knobs interact on the subscription path only: a subscription `delivery_key` hmacs the `timestamp`, so a resend with a new timestamp is always a new delivery, while a byte-identical replay only reaches the dedupe layer within `tolerance_seconds` — a `lease_seconds` at or above `tolerance_seconds` therefore leaves no window in which a byte-identical subscription replay can recover a hard-killed dispatch. Events: `PaymentSucceeded`, `PaymentCancelled`, catch-all `WebhookReceived`:

```php
use Uzbek\Wlcm\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $event->payload->paymentId;
    $event->payload->externalId;
});
```

Or class handler: implement `WlcmWebhookHandler`, set `wlcm.webhook.handler`. A class that exists but does not implement the interface throws `MissingConfigurationException` while handling the delivery; a typo'd class name dies earlier, in the container. Neither fires at boot — both surface on the first webhook. Subscription webhooks: same route, detected by `event` field. Their signature base is per WLCM docs only, never confirmed against a real delivery — verify before production. Events: `SubscriptionActivated`, `SubscriptionCharged`, `SubscriptionChargeFailed`, `SubscriptionCancelled` (each carries `SubscriptionWebhookPayload`); `WebhookReceived` fires for both families. Own hook: implement `WlcmSubscriptionWebhookHandler`, set `wlcm.webhook.subscription_handler`. `external_user_id`, `subscription_id`, `amount` and `status` sit outside that base too — unauthenticated like the payment `amount` above, so reconcile before trusting them. Dedupe needs the `wlcm_transactions` table — enable `wlcm.storage.transactions`, off by default. Without it every delivery fires events again, replays included: a default install re-runs your listeners for any identical body resent inside the tolerance window, and, on a multi-worker server, fires them once per concurrent copy of a single delivery. With it on, both families dedupe: payment deliveries keyed `(connection, payment_id, state)`, subscription deliveries keyed `(connection, hmac_sha256(length_prefixed(external_order_id, event, timestamp), webhook_secret))` — length-prefixed because a bare colon join lets `("A:B", "C")` and `("A", "B:C")` collide, which silently swallowed one of two distinct deliveries — keyed with the connection's `webhook_secret` so the stored key cannot be walked back into the merchant reference it hashes. A payment redelivery is a duplicate only when the same `state` lands on a row already processed. A different `state` on the same `payment_id` dispatches again only when it moves the payment **forward** — `created` → `initiating` → `paid`/`cancelled`, plus the post-success cancel (-2). A backward move (an `initiating` or `created` notification arriving after a later one) is refused as `stale`: it is a replay, and letting it rewrite the row lets two states ping-pong it and re-dispatch a delivery still in flight. `paid` (2) and `cancelled-during-init` (-1) sit on the same rung, so neither can advance over the other: whichever lands first holds the row, and the other is recorded `stale` with no event. A `paid` arriving after a `cancelled-during-init` therefore does not fire `PaymentSucceeded` — reconcile with `orders()->status($orderId)` if you ever see both for one payment. A state the package does not model is accepted on a row that has not settled and refused on one that has, and both halves are worth stating outright. On a live row, two *different* unmodelled states each count as moving it forward, so each one claims and dispatches — `WebhookReceived` and the typed non-money events can fire more than once for a single payment while it is still in flight. On a settled row (2 / -1 / -2) an unmodelled state is `stale`: HTTP 200, a `stale` log entry, and **no event at all**, so do not build reconciliation on the assumption that an unrecognised state will always reach your listener. `PaymentSucceeded` fires only on state 2 and a settled row admits nothing but a cancel, so nothing unmodelled can reopen a payment that is already done. Accepting them on a live row is the deliberate half of that trade: refusing what we cannot order would eventually discard a real transition, and a lost payment event is worse than a repeated one your handler is already required to be idempotent about. Order per delivery is claim row → `WebhookReceived` + typed event → handler → mark processed, or release the claim if anything in that chain throws. **`processed_at` marks the end of the request, not the end of your work.** A `ShouldQueue` listener returns the instant the job is queued, so the claim is stamped processed while nothing has handled the payment yet — and if that queue never drains, every redelivery is answered `duplicate`, so WLCM's own retries cannot rescue it. The package cannot detect this: once the job is on the queue it has no way to learn whether it ran. If your listeners are queued, treat a stamped `processed_at` as "accepted for processing" and monitor your failed-jobs table as part of your payment reconciliation, or do the money-critical step synchronously and queue only what is safe to lose. Concurrent copies of one delivery race a single atomic write — a conditional `UPDATE` when a row already exists, an `INSERT` arbitrated by `unique(connection, payment_id)` when one does not: exactly one wins and dispatches, and the losers dispatch nothing. A loser logs `inflight` while the winner is still working, or `duplicate` if the winner finished before the loser looked. `WebhookReceived` is at-least-once per delivery, never once per concurrent copy — and this holds only with `wlcm.storage.transactions` on. With it off (the shipped default) there is no dedupe of any kind and every concurrent copy dispatches. With `wlcm.storage.logs` on, the delivery's log entry carries a `result` recording the verdict: `ok` the claim was taken and dispatch began — written before the events fire so a hard kill still leaves a trace, `failed` a second entry appended when the dispatch threw, so an `ok` with no successor is the signature of a worker that died mid-dispatch, `duplicate` this delivery was already processed, `stale` a payment delivery that does not move the row forward — a backward step down the ladder, or any state other than a cancel (-2) arriving at a settled row (2 / -1 / -2), including one the package does not model, `inflight` another worker holds a live claim on this exact delivery — it is being dispatched right now, by someone else, not by this request. `duplicate` and `inflight` stay distinguishable on purpose: one is done, the other is in progress. Subscription deliveries log `ok`, `duplicate`, `inflight` or `failed` — the same set as payments, since a subscription dispatch that throws appends its verdict the same way.

**Handlers must be idempotent.** A claim takes the row before your handler runs and `processed_at` is still stamped only after it returns. If anything in the dispatch throws, the claim is released on the spot and the next redelivery runs the whole dispatch again — events included. If the worker is killed outright (SIGKILL, OOM, host loss) nothing releases it, and the next redelivery re-runs the dispatch once `wlcm.webhook.lease_seconds` have elapsed; a redelivery arriving inside that window logs `inflight` and dispatches nothing. A handler that runs **longer** than `lease_seconds` can be dispatched twice for the same delivery — size the lease above your slowest handler. At-least-once, not exactly-once. Upgrading past the keyed, length-prefixed `delivery_key` invalidates subscription keys written by an older version, so a redelivery straddling the upgrade may dispatch once more — the same at-least-once contract, not a new hazard.

**Do not rely on webhooks alone.** Live deliveries are effectively at-most-once: failed delivery recorded with `attempts: 1`, no retry scheduled. Reconcile by polling — for orders still pending on your side, call `Wlcm::partner()->orders()->status($orderId)` and settle from `isPaid`/`stateEnum()`. Concurrent copies of a delivery no longer accidentally cover for a dispatch that died, so polling is now the only recovery for a lone hard-killed dispatch, not a second line of defence.

## Storage (optional)

```bash
php artisan vendor:publish --tag=wlcm-migrations
php artisan migrate
```

```php
'storage' => [
    'logs' => 'database',   // every outgoing request/response + webhook delivery
    'logs_channel' => null, // 'log' driver only; null = the app's default channel
    'logs_path' => null,    // 'file' driver only; null = storage_path('logs/wlcm.jsonl')
    'transactions' => true, // wlcm_transactions: checkout/payment state, updated by webhooks
    'prune_days' => 90,
    'stale_days' => null,   // reaps abandoned checkouts; null = keep forever
],
```

Both default `false` — nothing is recorded unless opted in. `wlcm_transactions` also carries `dispatch_token` and `claimed_at` — the in-flight claim: who is dispatching this delivery and when they took it. Both are cleared when the delivery is processed or the dispatch is released. There is deliberately no command to list or clear a stuck claim; the query is `WlcmTransaction::query()->whereNotNull('dispatch_token')->whereNull('processed_at')`, and a row it returns is either a dispatch running right now or one whose worker was killed — `claimed_at` against `lease_seconds` is what separates them. The suppressed copy leaves no trace of its own on a default install: `inflight` is recorded only as the `result` of an audit log entry — the `wlcm_logs` column under `'database'`, the same field in the JSONL under `'file'`, the channel record under `'log'` — and `storage.logs` is off unless you turn it on. `transactions` is a strict toggle: `true`/`'1'`/`1` on, `false`/`'0'`/`0`/`null`/`''` off, anything else throws `MissingConfigurationException` on the first recorded call — `'off'` is a typo, not an off switch. PAN, OTP, passwords, secrets always masked or dropped before the write, whichever driver is in use. Luhn-coincident numeric values (e.g. some ms timestamps) over-mask in log body copies. The dedicated id columns follow one rule on both tables: only the merchant's own free text is scrubbed — `external_id` on payments, `external_order_id` on subscriptions — because only a merchant can bury a PAN in one. WLCM's own `order_id`, `payment_id` and `transaction_id` are stored byte-intact apart from NUL bytes, which are stripped: Postgres truncates a value at the first NUL and MySQL does not, so leaving them in meant the two engines stored different ids for one delivery. `wlcm_logs` still joins `wlcm_transactions` on them exactly, because both surfaces strip identically. What the scrub costs is looking a row up by the raw *merchant reference* when that reference is itself a Luhn-passing card-shaped run — 13-19 unbroken digits (roughly one such numeric id in ten), or four groups of four split by spaces or dashes like `1234-5678-9012-3456` — which stores as `123456***3456`. Pass your reference through `Masker::scrubPans()` before the lookup and it matches again. Full request/response body blobs keep a whole-body scrub regardless, so a PAN the server echoes anywhere still never lands in a body copy. Database columns are fixed width (`external_id` 100 chars, other ids 64) and a longer value used to throw the insert, losing the whole audit row — values are now cut to the width and end `~` so the cut is visible. The untouched value stays in the log row's `request_body`/`response_body` JSON, which has no width limit — except that NUL bytes are stripped from values and percent-escaped in object keys (`%` becomes `%25` and NUL becomes `%00`, applied while building a fresh array so no two keys can collapse onto one and a merchant key is never overwritten — a key containing a literal `%` is rewritten for the same reason), and the `database` driver alone replaces a body deeper than 64 levels with a single encoded string under a `_wlcm.reshaped` marker. Nothing strips `_wlcm` from a body, so the marker alone is copyable — what a genuine reshape does is REPLACE the blob, leaving exactly the two keys `_wlcm` and `body`. On `request_body` that shape is conclusive: an inbound delivery mimicking the marker still has to carry the five required fields and the signature it was verified over, and those cannot fit in two keys. On `response_body` it is **not** conclusive and must not be treated as such — a server response has no required fields and no signature, so it can be byte-for-byte the two-key shape without any reshape having happened. Read a `_wlcm.reshaped` marker on an outbound row as a hint, and confirm against the request or the upstream response itself. The reshape exists because MySQL refuses a JSON document over 100 levels deep and the failed write would otherwise leave a dispatched delivery unaudited; the `file` and `log` drivers never cut anything. Lookups by a cut key are cut the same way, so webhook correlation still lands on its own row.

| `logs` | Where entries go | Env |
| --- | --- | --- |
| `false` (or `'0'`) | nowhere | `WLCM_LOGS=false`, `WLCM_LOGS=0` |
| `'database'` (or `true`/`'1'`) | `wlcm_logs` table — needs the migration | `WLCM_LOGS=database`, `WLCM_LOGS=1` |
| `'file'` | one JSON object per line at `logs_path` | `WLCM_LOGS=file`, `WLCM_LOGS_PATH` |
| `'log'` | one `info` record per entry on `logs_channel` | `WLCM_LOGS=log`, `WLCM_LOGS_CHANNEL` |

All three drivers record the same fields per entry — the `wlcm_logs` columns; outgoing entries carry the request/response ones, webhook entries only the delivery ones. A subscription delivery files its `external_order_id` under `external_id`, the same column `wlcm_transactions` puts it in, and leaves `order_id`/`payment_id` null — those name payment-family ids, and `subscription_id` in either would collide with one. The file driver appends with `LOCK_EX`, creates the directory if missing, and timestamps in ISO-8601. A relative `WLCM_LOGS_PATH` resolves against the process working directory — different for web, queue workers and CLI — so give it an absolute path. A typo'd `logs_channel` does not throw: Laravel falls back to its emergency logger and the entries land in `storage/logs/laravel.log` instead of the channel you named. Check the channel name first when entries go missing. Anything else throws `MissingConfigurationException` the first time the store resolves — a typo'd driver name fails loudly instead of silently dropping the audit trail. `null`, `''` and `'0'` count as off, so neither an unset env var nor `WLCM_LOGS=0` is a typo. The driver is picked once, when the store is first resolved — it is a singleton, so swapping between drivers at runtime needs a rebind to take effect: entries keep going to the driver that was resolved, not to the newly named one. They are not lost, which they were until recently — each driver used to ask "am I the configured driver", so a flip in a long-lived process (Octane, a queue worker, a warm container) silently sent every entry nowhere: no rows, no bytes, no error, no `report()`, delivery still 200. Turning logging off and back on at runtime keeps working: every driver rechecks the on/off state on each write, and a value it cannot read throws there too rather than quietly discarding the trail. What is deliberately *not* offered is a dispatcher that picks the driver per write — it would make runtime flips take effect and would also let a hand-constructed second driver stay silent, but it costs `app(LogStore::class)` returning your configured driver rather than a wrapper, which is a documented contract. The trade is recorded here so it does not have to be rediscovered: if runtime driver switching ever matters more than that contract, a dispatcher is the shape to build.

Both models are `MassPrunable`:

```php
Schedule::command('model:prune', ['--model' => [
    \Uzbek\Wlcm\Models\WlcmLog::class,
    \Uzbek\Wlcm\Models\WlcmTransaction::class,
]])->daily();
```

`prune_days` is the age window over every `wlcm_logs` row and over the `wlcm_transactions` rows that already carry `processed_at` — an in-flight delivery is never pruned out from under the dedupe check. Null or non-numeric switches that window off: `wlcm_logs` then prunes nothing at all, while `wlcm_transactions` still answers to `stale_days`. Numeric values clamp to a floor of 1 day. Keep `prune_days` above WLCM's redelivery window — a pruned row is a forgotten delivery, and a replay after it re-runs your handlers. `stale_days` is a second, independent window over `wlcm_transactions` — off by default. It reaps only abandoned checkouts: rows no webhook ever claimed, so `processed_at`, `payment_id` and `delivery_key` are all null. A claimed row still holding `processed_at` null is a delivery that crashed mid-dispatch, and neither window reaps it at any setting — reaping one makes WLCM's next redelivery look fresh and re-runs your handlers. `dispatch_token` and `claimed_at` are never prune predicates. Reaping an abandoned checkout costs only the checkout-time linkage: a late webhook for it still dispatches, as a new row. The two windows disable and clamp independently; a prune run applies whichever are set. Pruning covers those two tables only. The file driver's JSONL grows forever — rotate it yourself; the `'log'` driver inherits whatever retention its channel has. A failing audit sink is swallowed and `report()`ed so it never breaks a payment call — but if the app's default log channel is broken too, `report()` itself can throw and surface to the caller. Keep that channel healthy. Log writes go through the `Uzbek\Wlcm\Storage\LogStore` interface; rebind it to send audit entries somewhere none of the shipped drivers reach. Its `webhook()` takes a fourth `array $ids` argument carrying the correlation columns off the raw delivery — server ids byte-intact, merchant reference scrubbed — because the blob it is handed is whole-body scrubbed and that would mangle a Luhn-coincident `order_id`; omit it and the store falls back to reading the blob. An implementation written against the three-argument signature will not satisfy the interface. Transaction rows go through `Uzbek\Wlcm\Storage\TransactionStore` (shipped binding: `TransactionRecorder`); rebind it to keep checkout and webhook-dedupe state outside `wlcm_transactions`. `applyWebhook`, `markProcessed`, `claimSubscription` and `markSubscriptionProcessed` take a `string $token`, and `releaseClaim`/`releaseSubscriptionClaim` are new — an implementation written against the old signatures will not satisfy the interface. A rebound store must also decide the claim by the affected-row count of one atomic statement; one that decides by reading `processed_at` reintroduces concurrent duplicate dispatch, and nothing in the type system will tell you. Neither a webhook claim nor `checkout()->create()` runs inside a caller-opened database transaction: both are refused with `CallerTransactionException` while `wlcm.allow_caller_transaction` is `false`, the default. The row rolls back and the consequence does not — a claim taken inside your transaction vanishes with it while the events it guarded have already fired in process, so the next redelivery fires them again; a checkout created inside it leaves WLCM holding a real payment with no row linking it to your order. The checkout guard runs *before* the request leaves the process, so a refusal means nothing was sent — the message says so, because a throw from a payment call otherwise reads as the worst case and invites a double submit. Setting the key `true` restores the previous behaviour exactly, and is what you want if your own middleware wraps requests in a transaction — but then both hazards are yours, along with the original one: every claim joins your transaction, so concurrent writers block instead of losing the race and no claim commits until it ends. The guard is consulted only when `wlcm.storage.transactions` is on; with no row to lose there is nothing to protect, and refusing anyway would only teach you to set the opt-out for a reason that does not apply. Two residuals, deliberately: the other state-changing calls — subscription create, split, fiscal register — can still lose their `wlcm_logs` audit row to a caller rollback, which is audit loss rather than money loss and is **not** covered by this flag (if a money hazard is ever measured on one of them, the fix is to widen this guard, not to add a second one); and a rebound `TransactionStore` bypasses the inbound half entirely, because the guard lives at claim time inside the shipped recorder. Both bindings are singletons handed to the manager at construction — rebind before the first `Wlcm::` call of the process, or the **outbound** clients keep the old one. That caveat is one-sided, and the asymmetry is worth knowing because it splits your audit trail: the webhook controller is resolved per request and constructor-injects whatever is bound at that moment, so a **late rebind is ignored outbound and honoured inbound**. Rebind late and outgoing calls keep landing in the old sink while webhook deliveries go to the new one. Rebind before the first `Wlcm::` call and both agree. Binding order relative to the provider does not matter: the package registers with `singletonIf`, so a store you bind before its provider runs — `Application::configure()->withSingletons([...])` — is kept rather than overwritten. One more consequence of rebinding `TransactionStore` specifically: **the caller-transaction guard described above lives at claim time inside the shipped recorder, so a rebound store does not get the inbound half of it** — your `checkout()` calls are still refused inside an open transaction, your webhook claims are not. If your store keeps a dedupe row, apply the same rule in it.

## Testing

```php
use Uzbek\Wlcm\Facades\Wlcm;

// Call once per test — the first call's stubs (including its catch-all)
// win over anything registered by a later Http::fake() or Wlcm::fake().
Wlcm::fake();
```

No WLCM config needed. `fake()` fills `base_url`, `api_key`, `api_secret`, `webhook_secret` on the default connection with dummies, but only where the value is missing or empty — a configured value is never clobbered. Call it before the first `Wlcm::` call of the test: the manager caches the `Connection` and the client captures its config at construction, so a `fake()` running after the client exists leaves that client on the old config. Unstubbed endpoints throw. The catch-all answers HTTP 500, so `ServerException` comes out with message `wlcm stub missing for this endpoint` and `$e->requestPath` naming the URL — nothing success-shaped to mistake for a real answer. `fake()` installs that catch-all as a global `Http::fake()` `*`: it stubs every outbound HTTP call in the app, not just WLCM's, so anything else your test talks to over HTTP gets that 500. The filled `webhook_secret` is the published constant `whsec_fake`, so webhook tests running under `fake()` verify signatures against a value printed in this README — they prove wiring, not secrecy. Set a real secret in config before calling `fake()` when the secret is what you are testing. Custom responses + assertions (`$fake->assertNothingSent()` asserts no calls):

```php
$fake = Wlcm::fake([
    '*/integrations/checkout' => ['order_id' => 42, 'state' => 0, 'checkout_url' => 'https://custom.test'],
]);

Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-7');

$fake->assertCheckoutCreated(fn (array $payload): bool => $payload['external_id'] === 'ORD-7');
$fake->assertSent(fn ($request) => true);
```

## Sandbox

Base URL: `https://apidev.wlcm.uz/api/v1`. `mock_payme` — sandbox-only provider, pass raw string (`provider: 'mock_payme'`); not in `PaymentProvider` enum, invalid in production. Hosted test page: any card number, straight to OTP. Active: `payme`, `click`, `mock_payme`. Inactive: `uzum`, `paylov`, `card`. Fiscal register returns HTTP 500 even for paid payments — sandbox OFD unwired. Subscription `attachCard` fails `card_not_found` (no Paylov test cards) — `activate`/`charge` untestable end-to-end.

## Verify on production before v1.0.0

Live-verified against sandbox; re-check these before depending on them:

- **Fixed split unit** — sandbox-proven SUM, docs claim tiyin. SDK sends SUM by default, tiyin retained via `split_amount_unit`. Confirmation pending (2026-07-25)
- **`confirmPaylov` body casing** — camelCase `{transactionId, otp}` from sandbox console, unverified live
- **Card checkout** — `expire_date` format (`"3003"`-style) + OTP confirm round-trip unverified against real card provider
- **Subscription activate/charge + webhooks** — blocked on Paylov test cards; webhook signature base per docs only
- **Webhook retry policy** — none observed on sandbox (`attempts: 1`, no `next_retry_at`); build for at-most-once either way
- **Webhook object / events history shapes** — parsed defensively from sandbox responses
- **Domain separation in the signature bases** — the payment and subscription bases are both bare colon joins with no domain tag, so one can be made byte-identical to the other. The package refuses the shapes that allow it, at the cost of forbidding a colon in `external_order_id`. Ask WLCM to add a domain tag or length-prefixing to both bases, which removes the ambiguity at the source and lets the restriction be dropped
- **Both `-1` and `2` for one payment** — can a single payment emit `cancelled-during-init` (-1) *and* `paid` (2), and can deliveries arrive out of order? They share a ladder rung today, so a `paid` landing second is refused as `stale` and fires no event. Never observed; if WLCM confirms it can happen, the rungs need revisiting with that evidence
- **Webhook 5xx handling** — whether WLCM deactivates a webhook after repeated 5xx (`is_active` / `last_status_code` on the webhook object). Concurrent losers now return 200 rather than 500, so this is less pressing than it was, but a contended-out claim still 500s

Sandbox-resolved (2026-07): `me` path is `/partners/me` (docs' `/integrations/me` 404s); webhook secret = per-webhook `secret_key` chosen at create; OTP confirm path is `/integrations/payment/card/confirm` (sandbox console's `/integrations/card/confirm` has no route).
