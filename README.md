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

Multiple partners: entries under `wlcm.connections` in `config/wlcm.php`, then `Wlcm::connection('name')->partner()->...` where the examples below say `Wlcm::partner()->...`. `Wlcm::partner()` is shorthand for the `wlcm.default` connection.

## Money

All amounts `int` tiyin (1 sum = 100 tiyin) — params, responses, everything you pass. Package converts to sum where API wants sum (subscription create/charge, fixed splits by default — see `split_amount_unit`). Inbound SUM values stay raw on DTO; `amountTiyin()` accessor uses digit math, never float.

Non-positive amounts throw `InvalidAmountException`: `checkout()->create()` rejects `amountTiyin <= 0`. Subscription `create`/`charge` convert through `Tiyin::toSum`, which also demands divisibility by 100 — `4990000` ships as `49900`, `4990001` throws.

Fixed split amounts must be a real `int`. A string or float throws `InvalidAmountException` in both unit modes, before any conversion — a numeric string would ship the wrong unit silently.

Inbound SUM strings are validated, never silently zeroed: `amountTiyin()` throws `InvalidAmountException` on anything but digits with at most two decimal places (optional leading `-`), and on a whole part of 17+ digits.

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

Card checkout: `provider: 'card'` + `cardNumber` + `expireDate`, then `Wlcm::partner()->payments()->confirmOtp($transactionId, $cid, $otp)`.

Other calls:

- `Wlcm::partner()->partners()->me()` — partner info
- `Wlcm::partner()->providers()->all()` — payment providers (public endpoint)
- `Wlcm::partner()->orders()->status($id)` — `isPaid`, `stateEnum()`, `amount` (raw SUM float), `amountTiyin()` (public endpoint)
- `Wlcm::partner()->payments()->cancel($id, reason: 1)` — `reason` = int code
- `Wlcm::partner()->payments()->confirmPaylov($transactionId, $otp)` — UNVERIFIED: camelCase body from sandbox console, not confirmed live
- `Wlcm::partner()->webhooks()` — `create($url, $secret)`, `all()`, `update()`, `events()` (delivery history), `delete()`. `secret_key` chosen at create goes into `WLCM_WEBHOOK_SECRET`

## Subscriptions

Recurring charge on bound card. Partner calls HMAC-signed; card attach/confirm/activate = unsigned end-user calls.

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

Lifecycle: `pending → card_attached → active → cancelled/expired`, plus `paused` after 3 failed charges. `cancel()` returns updated `Subscription`.

## Split

```php
$split = Wlcm::partner()->payments()->split(7788, [
    ['recipient_id' => 10, 'recipient_type' => 'brand', 'split_type' => 'percent', 'amount' => 70],
    ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => 100000], // tiyin
]);

Wlcm::partner()->payments()->getSplit(7788);
```

Fixed amounts int tiyin like everything else — non-int throws. Wire unit via `wlcm.connections.{connection}.partner.split_amount_unit`: default `'sum'` converts for you; `'tiyin'` passes raw. Unit is sandbox-proven SUM (server errors compare fixed totals against payment SUM value); WLCM docs claim tiyin. Final WLCM confirmation pending (2026-07-25).

> **Warning:** a wrong `split_amount_unit` misplaces money by a factor of 100. Do not flip this config without WLCM's confirmation.

Server rules (WLCM-enforced): fixed total must equal payment amount exactly; duplicate recipients rejected 422; unknown recipients 404. Percent values (0-100) never converted. Response amounts stay raw decimal SUM strings on DTO.

Response rows that are not arrays get dropped from `$split->splits` instead of hydrated into zeroed recipients — compare `count($split->splits)` against `$split->totalRecipients` to detect it. `$split->raw` keeps the untouched body either way.

## Fiscalization

```php
$fiscal = Wlcm::partner()->fiscalization()->get(paymentId: 7788);

$registered = Wlcm::partner()->fiscalization()->register(
    items: [['title' => 'Premium subscription', 'price' => 120000, 'count' => 1, 'vat_percent' => 12]],
    paymentId: 7788,
);

$refunded = Wlcm::partner()->fiscalization()->refund(paymentId: 7788);
```

Item amounts passthrough — SDK never converts them. Docs say tiyin; caller sends what API expects. (Sandbox OFD unwired, register returns 500.)

## Webhooks

Route auto-registered `POST /wlcm/webhook/{connection?}` (path via `wlcm.webhook.path`). The `{connection}` segment is constrained to the keys of `wlcm.connections` as read when routes load — an unknown name 404s, and a connection added to config after boot does not widen the list.

Signature verified against `wlcm.connections.{connection}.partner.webhook_secret` before your code runs; invalid = 403, no listener fires. Missing or non-scalar *required* field = 422 — payments need `order_id`, `payment_id`, `amount`, `state`, `timestamp`; subscriptions need `external_order_id`, `event`, `timestamp`. Optional fields are never checked and coerce to a typed default — `''` on payments, `''`/`0`/`'0'` on subscriptions; `signature` is required too, but checked on its own rather than through either list. Secret absent from config = `MissingConfigurationException`.

Signature base is `{order_id}:{payment_id}:{state}:{timestamp}` for payments, `{external_order_id}:{event}:{timestamp}` for subscriptions.

> **`amount` and `external_id` are in neither base.** They arrive unauthenticated — a valid signature says nothing about them. Never fulfill on the webhook's amount; call `Wlcm::partner()->orders()->status($orderId)` and settle from that.

Freshness: `wlcm.webhook.tolerance_seconds` (default 300) 403s a delivery whose `timestamp` sits further than that from now, in either direction; an unreadable timestamp is rejected the same way. `null` is the only way to switch the check off — any other non-numeric or non-positive value throws `MissingConfigurationException`. Freshness is not dedupe: an identical delivery replayed inside the window verifies fine.

Events: `PaymentSucceeded`, `PaymentCancelled`, catch-all `WebhookReceived`:

```php
use Uzbek\Wlcm\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $event->payload->paymentId;
    $event->payload->externalId;
});
```

Or class handler: implement `WlcmWebhookHandler`, set `wlcm.webhook.handler`. A class that exists but does not implement the interface throws `MissingConfigurationException` while handling the delivery; a typo'd class name dies earlier, in the container. Neither fires at boot — both surface on the first webhook.

Subscription webhooks: same route, detected by `event` field. Their signature base is per WLCM docs only, never confirmed against a real delivery — verify before production. Events: `SubscriptionActivated`, `SubscriptionCharged`, `SubscriptionChargeFailed`, `SubscriptionCancelled` (each carries `SubscriptionWebhookPayload`); `WebhookReceived` fires for both families. Own hook: implement `WlcmSubscriptionWebhookHandler`, set `wlcm.webhook.subscription_handler`. `external_user_id`, `subscription_id`, `amount` and `status` sit outside that base too — unauthenticated like the payment `amount` above, so reconcile before trusting them.

Dedupe needs the `wlcm_transactions` table — enable `wlcm.storage.transactions`, off by default. Without it every delivery fires events again, replays included: a default install re-runs your listeners for any identical body resent inside the tolerance window.

With it on, both families dedupe: payment deliveries keyed `(connection, payment_id, state)`, subscription deliveries keyed `(connection, sha256("{external_order_id}:{event}:{timestamp}"))`. A payment redelivery is a duplicate only when the same `state` lands on a row already processed; a different `state` on the same `payment_id` is a transition and dispatches again, unless the terminal-state rule below refuses it as `stale`. Order per delivery is claim row → `WebhookReceived` + typed event → handler → mark processed. `WebhookReceived` fires once per unique delivery.

**Handlers must be idempotent.** `processed_at` is stamped only after your handler returns, so a crash mid-dispatch leaves the row claimed but unprocessed and the next redelivery runs the whole dispatch again — events included. At-least-once, not exactly-once.

With `wlcm.storage.logs` on, `wlcm_logs.result` records the verdict: `ok` dispatched, `duplicate` this delivery was already processed, `stale` a payment delivery that would move a row out of a terminal state (2 / -1 / -2) into anything but a cancel (-2). Subscription deliveries only ever log `ok` or `duplicate`.

**Do not rely on webhooks alone.** Live deliveries are effectively at-most-once: failed delivery recorded with `attempts: 1`, no retry scheduled. Reconcile by polling — for orders still pending on your side, call `Wlcm::partner()->orders()->status($orderId)` and settle from `isPaid`/`stateEnum()`.

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
],
```

Both default `false` — nothing is recorded unless opted in. PAN, OTP, passwords, secrets always masked or dropped before the write, whichever driver is in use.

| `logs` | Where entries go | Env |
| --- | --- | --- |
| `false` (or `'0'`) | nowhere | `WLCM_LOGS=false`, `WLCM_LOGS=0` |
| `'database'` (or `true`/`'1'`) | `wlcm_logs` table — needs the migration | `WLCM_LOGS=database`, `WLCM_LOGS=1` |
| `'file'` | one JSON object per line at `logs_path` | `WLCM_LOGS=file`, `WLCM_LOGS_PATH` |
| `'log'` | one `info` record per entry on `logs_channel` | `WLCM_LOGS=log`, `WLCM_LOGS_CHANNEL` |

All three drivers record the same fields per entry — the `wlcm_logs` columns; outgoing entries carry the request/response ones, webhook entries only the delivery ones. The file driver appends with `LOCK_EX`, creates the directory if missing, and timestamps in ISO-8601.

A relative `WLCM_LOGS_PATH` resolves against the process working directory — different for web, queue workers and CLI — so give it an absolute path.

A typo'd `logs_channel` does not throw: Laravel falls back to its emergency logger and the entries land in `storage/logs/laravel.log` instead of the channel you named. Check the channel name first when entries go missing.

Anything else throws `MissingConfigurationException` the first time the store resolves — a typo'd driver name fails loudly instead of silently dropping the audit trail. `null`, `''` and `'0'` count as off, so neither an unset env var nor `WLCM_LOGS=0` is a typo.

The driver is picked once, when the store is first resolved — it is a singleton, so swapping between drivers at runtime needs a rebind. Turning logging off and back on at runtime keeps working: every driver rechecks the config on each write.

Both models are `MassPrunable`:

```php
Schedule::command('model:prune', ['--model' => [
    \Uzbek\Wlcm\Models\WlcmLog::class,
    \Uzbek\Wlcm\Models\WlcmTransaction::class,
]])->daily();
```

`prune_days` null or non-numeric disables pruning outright — empty query, no rows touched; numeric values clamp to a floor of 1 day. Transactions prune only rows with `processed_at` set, so an in-flight delivery is never pruned out from under the dedupe check. Keep `prune_days` above WLCM's redelivery window — a pruned row is a forgotten delivery, and a replay after it re-runs your handlers.

`prune_days` covers those two tables only. The file driver's JSONL grows forever — rotate it yourself; the `'log'` driver inherits whatever retention its channel has.

A failing audit sink is swallowed and `report()`ed so it never breaks a payment call — but if the app's default log channel is broken too, `report()` itself can throw and surface to the caller. Keep that channel healthy.

Log writes go through the `Uzbek\Wlcm\Storage\LogStore` interface; rebind it to send audit entries somewhere none of the shipped drivers reach.

## Testing

```php
use Uzbek\Wlcm\Facades\Wlcm;

// Call once per test — the first call's stubs (including its catch-all)
// win over anything registered by a later Http::fake() or Wlcm::fake().
Wlcm::fake();
```

No WLCM config needed. `fake()` fills `base_url`, `api_key`, `api_secret`, `webhook_secret` on the default connection with dummies, but only where the value is missing or empty — a configured value is never clobbered.

Call it before the first `Wlcm::` call of the test. The manager caches the `Connection` and the client captures its config at construction, so a `fake()` running after the client exists leaves that client on the old config.

Unstubbed endpoints throw. The catch-all answers HTTP 500, so `ServerException` comes out with message `wlcm stub missing for this endpoint` and `$e->requestPath` naming the URL — nothing success-shaped to mistake for a real answer.

`fake()` installs a global `Http::fake()` with a `*` catch-all: it stubs every outbound HTTP call in the app, not just WLCM's. Anything else your test talks to over HTTP gets that 500.

The filled `webhook_secret` is the published constant `whsec_fake`, so webhook tests running under `fake()` verify signatures against a value printed in this README — they prove wiring, not secrecy. Set a real secret in config before calling `fake()` when the secret is what you are testing.

Custom responses + assertions:

```php
$fake = Wlcm::fake([
    '*/integrations/checkout' => ['order_id' => 42, 'state' => 0, 'checkout_url' => 'https://custom.test'],
]);

Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-7');

$fake->assertCheckoutCreated(fn (array $payload): bool => $payload['external_id'] === 'ORD-7');
$fake->assertSent(fn ($request) => true);
```

`$fake->assertNothingSent()` asserts no calls.

## Sandbox

- Base URL: `https://apidev.wlcm.uz/api/v1`
- `mock_payme` — sandbox-only provider, pass raw string (`provider: 'mock_payme'`); not in `PaymentProvider` enum, invalid in production. Hosted test page: any card number, straight to OTP
- Active: `payme`, `click`, `mock_payme`. Inactive: `uzum`, `paylov`, `card`
- Fiscal register returns HTTP 500 even for paid payments — sandbox OFD unwired
- Subscription `attachCard` fails `card_not_found` (no Paylov test cards) — `activate`/`charge` untestable end-to-end

## Verify on production before v1.0.0

Live-verified against sandbox; re-check these before depending on them:

- **Fixed split unit** — sandbox-proven SUM, docs claim tiyin. SDK sends SUM by default, tiyin retained via `split_amount_unit`. Confirmation pending (2026-07-25)
- **`confirmPaylov` body casing** — camelCase `{transactionId, otp}` from sandbox console, unverified live
- **Card checkout** — `expire_date` format (`"3003"`-style) + OTP confirm round-trip unverified against real card provider
- **Subscription activate/charge + webhooks** — blocked on Paylov test cards; webhook signature base per docs only
- **Webhook retry policy** — none observed on sandbox (`attempts: 1`, no `next_retry_at`); build for at-most-once either way
- **Webhook object / events history shapes** — parsed defensively from sandbox responses

Sandbox-resolved (2026-07): `me` path is `/partners/me` (docs' `/integrations/me` 404s); webhook secret = per-webhook `secret_key` chosen at create; OTP confirm path is `/integrations/payment/card/confirm` (sandbox console's `/integrations/card/confirm` has no route).
