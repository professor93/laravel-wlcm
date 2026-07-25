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

Multiple partners: entries under `wlcm.connections` in `config/wlcm.php`, then `Wlcm::connection('name')` instead of `Wlcm::partner()`.

## Money

All amounts `int` tiyin (1 sum = 100 tiyin) — params, responses, everything you pass. Package converts to sum where API wants sum (subscription create/charge, fixed splits by default — see `split_amount_unit`). Inbound SUM values stay raw on DTO; `amountTiyin()` accessor uses digit math, never float.

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

Fixed amounts int tiyin like everything else. Wire unit via `wlcm.connections.{connection}.partner.split_amount_unit`: default `'sum'` converts for you; `'tiyin'` passes raw. Unit is sandbox-proven SUM (server errors compare fixed totals against payment SUM value); WLCM docs claim tiyin. Final WLCM confirmation pending (2026-07-25).

> **Warning:** a wrong `split_amount_unit` misplaces money by a factor of 100. Do not flip this config without WLCM's confirmation.

Server rules (WLCM-enforced): fixed total must equal payment amount exactly; duplicate recipients rejected 422; unknown recipients 404. Percent values (0-100) never converted. Response amounts stay raw decimal SUM strings on DTO.

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

Route auto-registered `POST /wlcm/webhook/{connection?}` (path via `wlcm.webhook.path`). Signature verified against `wlcm.connections.{connection}.partner.webhook_secret` before your code runs; invalid = 403, no listener fires.

Events: `PaymentSucceeded`, `PaymentCancelled`, catch-all `WebhookReceived`:

```php
use Uzbek\Wlcm\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $event->payload->paymentId;
    $event->payload->externalId;
});
```

Or class handler: implement `WlcmWebhookHandler`, set `wlcm.webhook.handler`.

Subscription webhooks: same route, detected by `event` field. Own signature base (`{external_order_id}:{event}:{timestamp}` — per WLCM docs, not confirmed against real delivery; verify before production). Events: `SubscriptionActivated`, `SubscriptionCharged`, `SubscriptionChargeFailed`, `SubscriptionCancelled` (each carries `SubscriptionWebhookPayload`); `WebhookReceived` fires for both families. Own hook: implement `WlcmSubscriptionWebhookHandler`, set `wlcm.webhook.subscription_handler`.

Dedupe needs `wlcm_transactions` table — enable `wlcm.storage.transactions`. Without it every delivery fires events again.

**Do not rely on webhooks alone.** Live deliveries are effectively at-most-once: failed delivery recorded with `attempts: 1`, no retry scheduled. Reconcile by polling — for orders still pending on your side, call `Wlcm::partner()->orders()->status($orderId)` and settle from `isPaid`/`stateEnum()`.

## Storage (optional)

```bash
php artisan vendor:publish --tag=wlcm-migrations
php artisan migrate
```

```php
'storage' => [
    'logs' => true,         // wlcm_logs: every outgoing request/response + webhook deliveries
    'transactions' => true, // wlcm_transactions: checkout/payment state, updated by webhooks
    'prune_days' => 90,
],
```

Both default `false` — no rows unless opted in. PAN, OTP, passwords, secrets always masked or dropped before log write, regardless of config.

`WlcmLog` is `MassPrunable`:

```php
Schedule::command('model:prune', ['--model' => [\Uzbek\Wlcm\Models\WlcmLog::class]])->daily();
```

## Testing

```php
use Uzbek\Wlcm\Facades\Wlcm;

// Call once per test — the first call's stubs (including its catch-all)
// win over anything registered by a later Http::fake() or Wlcm::fake().
Wlcm::fake();
```

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
