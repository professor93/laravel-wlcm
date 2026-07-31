# laravel-wlcm

WLCM payment client for Laravel. Partner API (HMAC), webhooks, subscriptions, optional audit tables.

> Deep behaviour, caveats and production warnings live in **[ADVANCED.md](ADVANCED.md)**. Read it before going live — it covers signature verification, dedupe, idempotency, storage and the things that will surprise you.

## Install

```bash
composer require uzbek/laravel-wlcm
php artisan vendor:publish --tag=wlcm-config
```

```
WLCM_BASE_URL=          # includes /api/v1
WLCM_API_KEY=
WLCM_API_SECRET=
WLCM_WEBHOOK_SECRET=
```

Multiple partners: add entries under `wlcm.connections` in `config/wlcm.php`, then `Wlcm::connection('name')->partner()->...`. Plain `Wlcm::partner()` is shorthand for the `wlcm.default` connection.

## Money

**All amounts are `int` tiyin.** 1 sum = 100 tiyin. The package converts to sum wherever the API expects sum. Non-positive amounts throw `InvalidAmountException`.

```php
amountTiyin: 120000   // = 1200.00 sum
```

Inbound SUM values stay raw on the DTO; call `amountTiyin()` to convert. See [ADVANCED.md](ADVANCED.md#amounts) for conversion rules and the split-unit warning.

## Checkout

```php
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Enums\PaymentProvider;

$r = Wlcm::partner()->checkout()->create(
    amountTiyin: 120000,
    returnUrl: 'https://shop.test/return',
    externalId: 'ORD-100045',
    provider: PaymentProvider::Payme,
);

$r->checkoutUrl; // redirect the customer here
```

Card checkout: `provider: 'card'` + `cardNumber` + `expireDate`, then `payments()->confirmOtp($transactionId, $cid, $otp)`.

## Orders

Register an order first, choose the provider later:

```php
$order = Wlcm::partner()->orders()->create(amountTiyin: 250000, externalId: 'ORD-7');

$link = Wlcm::partner()->orders()->generateLink(
    provider: PaymentProvider::Payme,
    returnUrl: 'https://shop.test/return',
    orderId: $order->orderId,   // or externalId: 'ORD-7' — exactly one
);

$link->checkoutUrl; // redirect the customer here
```

`generateLink()` is repeatable — call it again with a different provider to switch, last call wins, any number of times and in any order.

> **Always use the URL from the most recent call.** `checkoutUrl` depends on the provider as well as the order: `payme` gives `/pay/{id}` while `click` gives `/pay/click/{id}`. Caching it across a provider switch sends payers to the wrong page.

Reusing an `externalId` on `create()` is a 409 `ConflictException`. Passing neither or both identifiers to `generateLink()` throws `InvalidOrderReferenceException` before anything is sent.

```php
$status = Wlcm::partner()->orders()->status($order->orderId);
$status->isPaid;
```

> An order that was never linked already reports `is_pending: true`. Settle on `isPaid`, never on pending.

## Onboarding

Bootstraps a partner from an onboarding token, before any key or secret exists. Needs only `base_url` in config:

```php
$onboarding = Wlcm::connection('main')->onboarding()->onboarding();

if ($onboarding->validateToken($token)) {
    $c = $onboarding->complete($token, 'Acme LLC');

    $c->apiKey;     // put in WLCM_API_KEY
    $c->apiSecret;  // put in WLCM_API_SECRET — shown ONCE, never again
    $c->warning;    // the server's own wording, show it to the operator
}
```

An invalid or expired token is a 400 `ValidationException` with `errorCode()` of `invalid_or_expired`; a missing one on `complete()` gives `token_required`. `validateToken()` returns `false` only when the server answers 200 without a truthy `valid` — a rejected token throws instead, because "expired" and "no" are different answers.

> **`api_secret` is shown once.** It is dropped before any log store sees it, so the package keeps no copy. Capture it at the call site or it is gone.

## Subscriptions

```php
$sub = Wlcm::partner()->subscriptions()->create(
    externalOrderId: 'order-1001',
    externalUserId: 'user-1001',
    amountTiyin: 4990000,
    returnUrl: 'https://app.test/return',
);

$card = Wlcm::partner()->subscriptions()->attachCard($sub->id, $cardNumber, $expireDate, $phone);
Wlcm::partner()->subscriptions()->confirmCard($sub->id, $otp, $card->cardId);
Wlcm::partner()->subscriptions()->activate($sub->id);

$charge = Wlcm::partner()->subscriptions()->charge($sub->id, 4990000, $card->cardId);

if (! $charge->succeeded()) {
    // handle the failed charge
}
```

> **A failed charge does not throw.** The server answers 200 with `status: "failed"` either way — always check `succeeded()`.

Also: `get()`, `findByExternalOrderId()`, `cancel()`.

## Split

```php
Wlcm::partner()->payments()->split(7788, [
    ['recipient_id' => 10, 'recipient_type' => 'brand', 'split_type' => 'percent', 'amount' => 70],
    ['recipient_id' => 22, 'recipient_type' => 'partner', 'split_type' => 'fixed', 'amount' => 100000],
]);

Wlcm::partner()->payments()->getSplit(7788);
```

> **A wrong `split_amount_unit` misplaces money by a factor of 100.** Default `'sum'` is sandbox-proven correct. See [ADVANCED.md](ADVANCED.md#splits).

## Fiscalization

```php
Wlcm::partner()->fiscalization()->get(paymentId: 7788);
Wlcm::partner()->fiscalization()->register(items: [...], paymentId: 7788);
Wlcm::partner()->fiscalization()->refund(paymentId: 7788);
```

## Webhooks

Route auto-registered at `POST /wlcm/webhook/{connection?}`. Signature verified before your code runs; invalid is a 403 and nothing fires.

```php
use Uzbek\Wlcm\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $event->payload->paymentId;
});
```

Events: `PaymentSucceeded`, `PaymentCancelled`, `WebhookReceived`, plus `SubscriptionActivated`, `SubscriptionCharged`, `SubscriptionChargeFailed`, `SubscriptionCancelled`. Or implement `WlcmWebhookHandler` and set `wlcm.webhook.handler`.

> **`amount` and `external_id` are not signed.** Never fulfil on the webhook's amount — call `orders()->status($orderId)` and settle from that.

> **Handlers must be idempotent**, and deliveries are effectively at-most-once, so poll for anything still pending. Dedupe needs `wlcm.storage.transactions` on.

[ADVANCED.md](ADVANCED.md#webhooks-route-registration-and-the-enabled-toggle) covers verification, dedupe, the claim lease, host route conflicts and deploy-cache traps.

## Storage (optional)

```bash
php artisan vendor:publish --tag=wlcm-migrations
php artisan migrate
```

```php
'storage' => [
    'logs' => 'database',   // 'database' | 'file' | 'log' | false
    'transactions' => true, // needed for webhook dedupe
    'prune_days' => 90,
],
```

Both default **off** — nothing is recorded unless you opt in. PANs, OTPs, passwords and secrets are masked before any write. Both models are `MassPrunable`:

```php
Schedule::command('model:prune', ['--model' => [
    \Uzbek\Wlcm\Models\WlcmLog::class,
    \Uzbek\Wlcm\Models\WlcmTransaction::class,
]])->daily();
```

See [ADVANCED.md](ADVANCED.md#storage-what-gets-scrubbed) for scrub rules, driver behaviour, pruning and rebinding.

## Testing

```php
Wlcm::fake();

// or with stubs
$fake = Wlcm::fake([
    '*/integrations/checkout' => ['order_id' => 42, 'state' => 0, 'checkout_url' => 'https://custom.test'],
]);

$fake->assertCheckoutCreated(fn (array $payload): bool => $payload['external_id'] === 'ORD-7');
```

Call it once, before the first `Wlcm::` call. Unstubbed endpoints answer 500 so nothing looks like a real success. Details in [ADVANCED.md](ADVANCED.md#testing-details).

## Enums

| Enum | Values |
| --- | --- |
| `PaymentState` | `Created` 0, `Initiating` 1, `Success` 2, `CancelledDuringInit` -1, `Cancelled` -2 |
| `PaymentProvider` | `Payme`, `Click`, `Uzum`, `Paylov`, `Card` |
| `OrderState` | `Created` (`'created'`) |
| `SplitType` | `Percent`, `Fixed` |
| `WlcmErrorCode` | machine codes the server is known to send |

`stateEnum()`, `statusEnum()`, `providerEnum()` and `errorCodeEnum()` all return `null` for a value the enum does not model — always check before matching. The API answers with display casing (`Payme`), so parse inbound provider names with `PaymentProvider::fromName()`, not `tryFrom()`.

## Sandbox

`https://apidev.wlcm.uz/api/v1`. `mock_payme` is a sandbox-only provider — pass it as a raw string. Fiscal register and subscription card flows are not testable there. See [ADVANCED.md](ADVANCED.md#sandbox).

## License

MIT.
