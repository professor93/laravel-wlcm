# laravel-wlcm — advanced notes

Everything the README leaves out. Written for the person debugging a live payment, not for the person installing the package.

Each section here is load-bearing: it exists because the behaviour it describes surprised someone. Nothing in this file is decorative.

---

## Amounts

All amounts `int` tiyin (1 sum = 100 tiyin) — params, responses, everything you pass. Package converts to sum where the API wants sum (subscription create/charge, fixed splits by default — see `split_amount_unit`). Inbound SUM values stay raw on the DTO; `amountTiyin()` uses digit math, never float. Non-positive amounts throw `InvalidAmountException`: `checkout()->create()` rejects `amountTiyin <= 0`. Subscription `create`/`charge` convert through `Tiyin::toSum`, which also demands divisibility by 100 — `4990000` ships as `49900`, `4990001` throws. Fixed split amounts must be a real `int`: a string or float throws `InvalidAmountException` in both unit modes, before any conversion — a numeric string would ship the wrong unit silently. Inbound SUM strings are validated, never silently zeroed: `amountTiyin()` throws `InvalidAmountException` on anything but digits with at most two decimal places (optional leading `-`), and on a whole part of 17+ digits.

## Payment state

`stateEnum()` returns `?PaymentState` — check for null before matching on it. Null-honest on `OrderStatus` and `CheckoutResponse`: a non-numeric state, or one missing everywhere the DTO looks (for `OrderStatus`, both the top level and the `status_explanation` fallback), gives `null`. `WebhookPayload` is not — it casts state with `(int)`, so an empty or non-numeric value reads as `Created` (0) and fires no `PaymentSucceeded`/`PaymentCancelled`, a leading-digit string like `"2abc"` reads as `Success`, and only an unmodelled number (say `7`) yields null. A payment delivery whose `state` is missing, or empty (Laravel converts `''` to `null`), never reaches the DTO — 422 at verification.

## Provider names

`PaymentProvider` values are the lower-case strings the API accepts on the way out, but it answers with display casing (`Payme`, `Click`, `Uzum`), so `tryFrom()` misses every live row. Parse inbound names with `PaymentProvider::fromName()` — case-insensitive, `null` on anything with no case, including the sandbox-only `mock_payme`. `PaymentProviderInfo::providerEnum()`, `OrderStatus::gatewayEnum()` and `WebhookPayload::providerEnum()` already go through it; the raw string stays on the DTO either way.

## Error bodies

Error bodies come in three `detail` shapes and all three are read: a `{message, code, suggestions}` object (`getMessage()` from `message`, `errorCode()` from `code`), a plain string (`getMessage()`, plus `errorCode()` when it is bare snake_case), and pydantic's validation list (`getMessage()` from the first entry, field map on `errors()`). A top-level `code` outranks anything in `detail`. `errorCode()` hands back exactly what the server sent, so live UPPER_CASE codes like `ORDER_NOT_FOUND` arrive intact — `errorCodeEnum()` has no case for those and returns null, so match on `errorCode()` unless you know the code is in `WlcmErrorCode`.

## Splits

Fixed amounts int tiyin like everything else — non-int throws. Wire unit via `wlcm.connections.{connection}.partner.split_amount_unit`: default `'sum'` converts for you, `'tiyin'` passes raw. Unit is sandbox-proven SUM (server errors compare fixed totals against payment SUM value); WLCM docs claim tiyin. Final WLCM confirmation pending (2026-07-25).

> **Warning:** a wrong `split_amount_unit` misplaces money by a factor of 100. Do not flip this config without WLCM's confirmation.

Server rules (WLCM-enforced): fixed total must equal payment amount exactly; duplicate recipients rejected 422; unknown recipients 404. Percent values (0-100) never converted. Response amounts stay raw decimal SUM strings on the DTO. Response rows that are not arrays get dropped from `$split->splits` instead of hydrated into zeroed recipients — compare `count($split->splits)` against `$split->totalRecipients` to detect it; `$split->raw` keeps the untouched body either way.

## Subscriptions

**A charge that fails does NOT throw.** The server answers HTTP 200 with `status: "failed"` either way — always check `$charge->succeeded()` before fulfilling.

## Fiscalization

Item amounts passthrough — SDK never converts them. Docs say tiyin; caller sends what the API expects. (Sandbox OFD unwired, register returns 500.)

---

## Webhooks: route registration and the enabled toggle

Route auto-registered `POST /wlcm/webhook/{connection?}` (path via `wlcm.webhook.path`), unless `wlcm.webhook.enabled` says otherwise: `true`/`'1'`/`1` registers it, `false`/`'0'`/`0`/`null`/`''` does not, and anything else — `'off'`, `'no'`, `'disabled'` — throws `MissingConfigurationException` rather than leave a public endpoint serving on a value the package cannot read. The toggle is enforced **twice**, and the second one is the one that matters: routes are not registered at boot when it is off, *and* every delivery re-reads it and 404s if it is off. Registration alone was not enough — `php artisan route:cache` bakes the table while the toggle is on, after which boot never runs that check again, and a process reporting `enabled: false` kept accepting signed deliveries and firing events until someone ran `route:clear`. An unreadable value throws on the delivery rather than at boot in that same cached case. The `{connection}` segment is constrained to the keys of `wlcm.connections` as read when routes load — an unknown name 404s, and a connection added to config after boot does not widen the list.

## Webhooks: signature verification

Signature verified against `wlcm.connections.{connection}.partner.webhook_secret` before your code runs; invalid = 403, no listener fires. It is computed over the RAW request body, not `$request->input()`: a stock app's `TrimStrings` and `ConvertEmptyStringsToNull` run first, and verifying against values the framework rewrote refused deliveries WLCM had signed correctly. That applies when the request is DECLARED as JSON; anything else falls back to the framework-parsed input and is still verified against rewritten values — a known residual, left alone because refusing a non-JSON body on no evidence would invent an outage. The test is the declared encoding, not whether the body happens to parse: a JSON body sent as `text/plain` parses perfectly, and treating that as JSON once let the query string supply the dispatched values while the body supplied the signature. Whatever the encoding, the base, the guards, the freshness check, the dispatched payload and the logged blob all come from one document.

Signature base is `{order_id}:{payment_id}:{state}:{timestamp}` for payments, `{external_order_id}:{event}:{timestamp}` for subscriptions.

Two further refusals guard the shared signature format — a colon in either `event` or `external_order_id` is 422 (both bases are bare colon joins, so a captured payment delivery could otherwise replay as a subscription with its signature untouched; a colon in `event` or `external_order_id`, a colon in `order_id`/`payment_id`, a non-numeric `state`, and a `timestamp` that is not a plain epoch or ISO-style datetime are each refused; together they leave no accepted split of a captured base in either direction, and both directions are verified by tests that GENERATE every split rather than by argument — three hand-written completeness proofs of this guard were wrong before those tests existed, and a fourth version of the reverse generator was wrong again for assuming a merchant reference cannot contain a colon, which our guard only enforces on delivery and never stopped WLCM signing. The generated sets vary every field an attacker controls — the timestamp across all six accepted shapes, and the reference across one to four colon-separated parts, numeric and not. One variable is held on purpose and is the named limit of the claim: the `event` is taken as colon-free, because WLCM mints it from `subscription.` names that contain none).

**A subscription `external_order_id` may not contain a colon, and a payment `state` must be numeric.** These are shape restrictions, not a proof: they close every reading enumerable from a base WLCM would actually sign, but two bare colon joins with no domain tag remain ambiguous in principle. The proper fix is domain separation or length-prefixing in both signature bases, which is WLCM's wire format to change.

On the PAYMENT family a negative or unparseable `amount` is 422 — it rides outside the base, a negative stores as negative money on Postgres while MySQL rejects the write, and an unparseable one used to throw inside the claim and 500 with no audit row at all. Subscriptions are not amount-checked here: nothing stores their amount, so the same value cannot reach a money column.

Missing, empty or non-scalar *required* field = 422 — payments need `order_id`, `payment_id`, `amount`, `state`, `timestamp`; subscriptions need `external_order_id`, `event`, `timestamp`. Optional fields are never checked and coerce to a typed default — `''` on payments, `''`/`0`/`'0'` on subscriptions. A field sent as `""` is treated as absent either way, but by different routes: on the raw-body path the package itself treats empty as absent, and on the non-JSON fallback a stock app's `ConvertEmptyStringsToNull` has already converted it. One shared predicate decides it, so the router and the audit trail cannot disagree; `signature` is required too, but checked on its own rather than through either list. Secret absent from config = `MissingConfigurationException`.

> **`amount` and `external_id` are in neither base.** They arrive unauthenticated — a valid signature says nothing about them. Never fulfill on the webhook's amount; call `Wlcm::partner()->orders()->status($orderId)` and settle from that.

## Webhooks: host constraint

`wlcm.webhook.domain` (env `WLCM_WEBHOOK_DOMAIN`) restricts the route to one host. Null serves it everywhere, which is the default.

The value is trimmed, and a whitespace-only one collapses to null rather than being registered. That is not tidiness: `Route::domain('')` carries a host pattern matching every host while `RouteCollection::addToCollections()` files the route in the *domainless* table by truthiness — neither of the two behaviours anyone wants, and it is exactly what `domain(config(...))` produces from an unset key.

Setting a domain changes which half of the conflict watch sees your route. A domained route lives in a different table, so a host route capturing the same path without a domain is checked first on every host.

## Webhooks: IP allowlist

`wlcm.webhook.allowed_ips` (env `WLCM_WEBHOOK_ALLOWED_IPS`) refuses deliveries from outside the list. Absent, empty or whitespace means off.

Entries may be an exact address, a CIDR block, or an inclusive `start-end` range, in v4 or v6. An env value is always a string so CSV is the way to express a list there; a config file may use an array instead. Exact and CIDR matching goes through Symfony's `IpUtils`; only the dash range is ours, and it compares `inet_pton` packed bytes rather than per-octet arithmetic, which is what makes `1.2.3.250-1.2.5.5` work and what gets IPv6 for free.

**A malformed entry throws `MissingConfigurationException`, and every entry is validated before any matching happens.** Validating lazily would short-circuit on the first match, leaving a typo further down the list invisible until the day an address that should have matched it is refused — silently. The quiet failure is the dangerous one here: an operator who cannot see why an address is rejected tends to widen the list until something works.

**It runs before signature verification.** An address the endpoint does not serve should never reach the constant-time secret work, and the cheaper check belongs first. There is a test proving a disallowed address is refused even with a garbage signature.

The 403 carries no body detail. Telling an unknown caller which addresses are allowed — or that an allowlist exists at all — is free reconnaissance.

**A rejection writes one audit line** with `result = 'rejected'`, carrying the address and nothing else from the request. That path is by definition untrusted and its body has been verified against no signature, so the address is all that is kept. Signature rejections remain silent; this layer is the one where an operator can lock themselves out, so it is the one that explains itself.

### The proxy trap

Matching uses `$request->ip()`, which is your **load balancer** unless the host app configures TrustProxies. Behind a proxy without it, nothing WLCM sends can ever match, and every delivery is refused.

It fails closed, which is the right direction — but the symptom is a bare 403 with no reason. So when a refused request carries an `X-Forwarded-For` header **and** the app trusts no proxies, the package `report()`s a `WebhookProxyMisconfiguredException` naming the address it actually matched against. Reported, never thrown: the delivery is already refused by then, and this exists so the refusal has an explanation in your error tracker.

## Webhook console commands

```bash
php artisan wlcm:webhook:create   --url= --host= --secret= --connection= --json
php artisan wlcm:webhook:list     --connection= --active-only --json
php artisan wlcm:webhook:update   {id?} --url= --events= --active --inactive --connection= --json
php artisan wlcm:webhook:delete   {id?} --force --connection=
```

**Exit codes are distinguishable on purpose:** `0` success, `1` the server said no, `2` the operator did. A script that retries on API failure must not retry a contradictory flag combination.

**`create` derives the url rather than asking cold.** Host comes from `--host`, else `wlcm.webhook.domain`, else `APP_URL`; path from `wlcm.webhook.path`; and a non-default connection gets its segment appended. An operator typing the url from memory is how a webhook ends up one segment away from the route that would have verified it. `--url` skips derivation entirely.

**The secret is generated, not prompted** — 32 random bytes hex-encoded — because a human-chosen webhook secret is the weakest link in the signature scheme. `--secret` overrides. **What gets stored is the secret the server reports back**, not the one sent: WLCM may keep its own, and deliveries are signed with whatever it kept.

**`create` warns about a host WLCM cannot reach** (`localhost`, `127.0.0.1`, `::1`, `.test`, `.local`, `.localhost`). Such an endpoint registers perfectly and then never receives anything.

**`.env` writing follows the same append-only policy as `wlcm:onboard`** and never overwrites an existing `WLCM_WEBHOOK_SECRET` — that value may be signing live deliveries for an endpoint still registered. A non-default connection prints a config block instead, since only the default reads `WLCM_WEBHOOK_SECRET`.

**`delete` refuses this application's own endpoint without `--force`.** Deleting it stops every delivery and the symptom is silence: no error, just payments that never reconcile. Ownership is decided on host plus path, ignoring scheme and trailing slash, so a cosmetic difference cannot bypass the guard.

**`update` refuses an empty patch** with exit 2 rather than sending a no-op PATCH that would report success while changing nothing. `--active` and `--inactive` together are also a usage error.

**`--active-only` on `list` filters out only a definite `false`.** A null means WLCM did not report the field, and hiding an endpoint that cannot be classified would misrepresent the list.

## Return URL resolution

Order: the explicit `returnUrl:` argument, then `connections.{name}.checkout.return_url`, then `wlcm.checkout.return_url`, then nothing.

**Nothing means the key is omitted from the payload entirely**, so WLCM applies its own default. That is not the same as sending an empty string — the server accepts `return_url: ""` with a 200, and it means the payer never comes back. `generateLink()` is the exception: the server requires the field there, so an unresolved value falls back to whatever the caller passed.

The per-connection lookup goes through `ConnectionConfig::block()`, reading the connection name as an array key rather than a config path. A dotted name like `shop.uz` interpolated into a path reads as `connections[shop][uz]` and silently finds nothing — the bug class fix `7e49c91` addressed.

A value with no scheme that names a registered route is resolved through `route()`. The check is deliberately narrow: anything containing `://` or starting with `/` is treated as a url and passed through untouched, so a real url can never be mistaken for a route name whatever your routes happen to be called. An unregistered name is returned verbatim rather than throwing — a merchant whose route is only defined in another context should not have their checkout die over it.

## Webhook delivery history

`wlcm:webhook:events` reads WLCM's own delivery log. That answers a different question from `wlcm_logs`: the local trail records deliveries that ARRIVED, while this records what WLCM tried to send — including everything that never reached you, which is exactly the case you cannot diagnose locally.

The record shape, confirmed live:

```
{id, webhook_id, event_type, payload, status, attempts,
 response_code, response_body, error_message, next_retry_at, created_at}
```

`response_code` is null when no HTTP exchange happened at all (a malformed url, a DNS failure) and an integer when there was one. The table shows `—` rather than a blank for that, because the two failures are different. Across 59 real sandbox deliveries `attempts` was always `1` and `next_retry_at` always null — the at-most-once claim, confirmed.

`--limit` defaults to 20 and **says so when it truncates**. A partial view that looks complete is how an operator concludes a delivery never happened.

### DeliveryHealth

`wlcm.webhook.alerts` defines when a delivery needs attention. One predicate, `DeliveryHealth::problematic()`, is shared by `--failed` and by the scaffolded listeners, so the CLI and your code cannot disagree.

| Rule | Default |
| --- | --- |
| `status_not_in` | `['delivered']` |
| `has_error_message` | `true` |
| `response_code_not_in` | `[200, 201, 202, 204]` |
| `attempts_gte` | `2` |
| `stale_retry` | `true` |

Any rule set to null is off.

**These are several independent signals on purpose.** Across every delivery ever observed on this API, the status read `failed` — no successful delivery has been seen, so `'delivered'` is a **guess**. A predicate resting on it alone would silently pass everything the day the real vocabulary turned out to be `success` or `ok`. `has_error_message` is the rule that survives that: a delivery carrying an error text has failed whatever its status is called.

An empty or unrecognisable record is treated as unhealthy. Reporting it as fine is the failure this predicate exists to prevent.

## Listener scaffolding

`wlcm:webhook:listeners` writes into `app/Listeners/Wlcm`. Laravel 11+ discovers listeners by their `handle()` type-hint, so nothing needs registering — the class works the moment it exists.

Idempotent by default: an existing file is skipped, never silently replaced, and `--force` overwrites. Naming an unknown event is a usage error that writes nothing, because generating nothing in silence is how someone waits for a listener that will never fire.

Every stub carries the at-least-once caveat and the reminder that `amount` and `external_id` are unsigned. The generated files are checked with `php -l` in the package's own tests — a scaffolder that emits plausible-looking invalid PHP is a real failure mode.

## Storage: file driver rotation

`wlcm.storage.file` — `max_size`, `daily`, `max_files`. All off by default, and without them the JSONL grows until the disk does not: `prune_days` covers the two *tables* and nothing else.

Rotation is **rename-based**, which is compatible with the write path because each write reopens the file by name. A writer holding the old inode finishes its line into the rotated file rather than losing it, and the next write lands in a fresh one. Truncating in place could cut a line in half. There is a test that writes 20 entries across a rotation and counts the live file plus every archive, expecting exactly 20.

Archives take a `.1`, `.2` suffix and **never overwrite an existing one**. `max_files` keeps the newest N by mtime.

`daily` puts the date in the *filename* (`wlcm-2026-08-01.jsonl`) rather than checking whether the date changed since the last write, so two processes crossing midnight cannot disagree about whose turn it is to rotate. The name uses **UTC**, not `APP_TIMEZONE` — a filename is a filing decision, and a host that moves timezone must not start writing yesterday's file again.

An unreadable `max_size` switches size rotation off rather than guessing a limit. Everything here runs inside `FileLogStore`'s existing swallow-and-report guard, so a rotation failure can never break a payment call — the cost being a file that keeps growing while `report()` explains why, which is the right way round.

## Webhooks: host route conflicts

**A host route at the same method and URI silently replaces ours**, taking `VerifyWebhookSignature` out of the chain entirely — so merchant code runs on an unverified body, and `route:list` is the only symptom. The package watches for this once the route table is final and `report()`s a `WebhookRouteConflictException` naming the URI and the three ways out. It reports rather than throws on purpose: a boot-time throw would take down `route:list`, the one tool that shows you what happened.

What it checks is which route would actually serve a `POST` to that path, and whether that route carries the signature middleware — not whether the route is ours. So re-registering the path yourself *with* `VerifyWebhookSignature` attached can be recognised as deliberate and stay quiet — but **only when the middleware class itself is on the route**, either `->middleware(VerifyWebhookSignature::class)` or a `Route::middleware(VerifyWebhookSignature::class)->group(...)` wrapper, because the watch reads the route's own declaration and not the resolved stack.

**Four ordinary ways of attaching it are NOT recognised and will be reported even though your endpoint is verified**: a middleware alias (`->middleware('wlcm.verify')`), a named middleware group (`Route::middlewareGroup('wlcm', [...])`), middleware declared on the controller via `HasMiddleware`, and the parameterised form `VerifyWebhookSignature::class.':arg'`. If you use one of those, either attach the class on the route as well or expect a standing report. The reverse also holds: attaching the class and then removing it with `withoutMiddleware()` is **not** noticed, so the watch stays quiet on an endpoint that is no longer verified.

`Route::any()`, `Route::match()`, `Route::redirect()` (which is `any()` underneath) and a route whose parameter merely captures your path are all recognised as taking it — and so is any of those **inside a `Route::domain()` group**. Domained routes are handled by a rule of their own, because the host WLCM delivers to is not knowable while the app boots: **any domained POST route that captures the path without the middleware is reported, whatever its host.** That over-reports on purpose. A catch-all under `Route::domain('admin.example.com')` cannot take the slot for deliveries addressed to `api.example.com`, and it will still be named — a false alarm costs you a `route:list`, a miss costs you forged payment state. Two smaller over-reports come with it: a POST route flagged `->fallback()` is treated as an ordinary route although dispatch tries fallbacks last, and two domained routes on the same host are not ordered against each other, so a verifying one does not silence an unverified one behind it.

Four things about what the watch does and does not see. It runs at `booted()` **and** after the last bootstrapper, taking the report once — because with `route:cache` the compiled table is installed from a callback queued deeper than anything a package can queue, so the earlier point sees an empty table and only the later one sees the truth. The later point is not redundant without a route cache either: a provider that registers its own routes from an application `booted()` callback lands after our watch, and at our URI it evicts the package route and serves deliveries unverified — measured silent with one watch point, reported with two. The cost is one extra scan of the POST table per boot for an installation with no conflict. Until this release the watch had only the earlier point and was therefore **completely silent under `route:cache`** — alive in dev, dead in production. It also runs in **console**, as it always did on the uncached path: the package boots for `route:cache`, `migrate` and a scheduler tick like any other provider, so a real conflict surfaces in deploy output through your log stack. That is deliberate — `route:cache` is the command that creates the dangerous state, so it is the best moment to hear about it. Second, the path comes from `wlcm.webhook.path` rather than from our own registered route, which is necessary but was never sufficient on its own: a table baked while the collision already existed contains no route of ours to learn the path from. Third, the probed connection segments are exactly the keys of `wlcm.connections`; a host route sitting on a segment you never configured is not watched, because that URI is not one the package serves either.

Two residuals that are not shadowing problems. Change `wlcm.webhook.path` and rebuild routes without rebuilding config, and the watched path is the new one while the baked table still serves the old — nothing is reported, because nothing serves the path at all; that state fails loudly on its own through WLCM's failed deliveries. And an app that boots providers without going through `Application::bootstrapWith()` never reaches the later watch point, so under `route:cache` it gets no report; every Laravel HTTP and console kernel does go through it. `route:list | grep wlcm` after any change to your route files or that setting is still worth the five seconds.

## Webhooks: deploy caches

**Deploy caches change what this package sees, and one case is a security trap.** The package reads config at request time, never `env()` — so the webhook secret, the toggles and the tolerance window all take effect the moment the *config* does. But `php artisan config:cache` freezes `env()` into `bootstrap/cache/config.php`, so **rotating a webhook secret in `.env` alone is inert**: the old baked secret keeps verifying and dispatching while the new one 403s. A rotation that looks done is not done. Re-run `config:cache` (or clear it) as part of the rotation, and verify by sending a delivery signed with the old secret and confirming a 403. `route:cache` compounds it, because it reads the *cached* config — rebuilding routes without rebuilding the config bakes the stale `wlcm.webhook.path`. `wlcm.webhook.enabled` is safe under both, because it is re-read on every delivery rather than only at route registration.

## Webhooks: freshness and the claim lease

`wlcm.webhook.tolerance_seconds` (default 300) 403s a delivery whose `timestamp` sits further than that from now, in either direction. A datetime timestamp that carries no offset is read as **UTC**, which is what WLCM sends — never in your `APP_TIMEZONE`, which used to put a host at +5 five hours outside any tolerance a merchant would set and 403 every delivery it received. Forms that carry their own offset (`Z`, `+05:00`) keep it. A timestamp that is not a plain epoch (10 or 13 digits) or an ISO-style datetime is refused earlier and differently — 422 `Invalid field: timestamp`, whether or not freshness is enabled — because it is malformed rather than stale, and because an unconstrained trailing field is what allows one signature base to be re-read as the other. `null` is the only way to switch the check off — any other non-numeric or non-positive value throws `MissingConfigurationException`. Freshness is not dedupe: an identical delivery replayed inside the window verifies fine.

`wlcm.webhook.lease_seconds` (default 60) is how long a claimed delivery counts as in flight. Unlike `tolerance_seconds` there is **no `null` disable** — `null`, non-numeric or non-positive throws `MissingConfigurationException` on the first delivery. Set it above your slowest synchronous handler and at or below the request timeout that would kill it; 60 is chosen because php-fpm usually kills a request at 30-60s, so an expired lease means that request is definitely dead. Both ends of that window carry one lease of slack, because these timestamps are second-resolution: a claim stamped up to a lease in the future is treated as live — two workers milliseconds apart can otherwise land either side of a second boundary and each conclude the other's claim is bogus — and a claim is only reclaimed once a full lease has provably elapsed. A host running up to a lease fast can hold a row for as long as two leases; one running further ahead than that has its claim treated as dead and reclaimed immediately. The package assumes an NTP-synced fleet, exactly as `tolerance_seconds` already does. The two knobs interact on the subscription path only: a subscription `delivery_key` hmacs the `timestamp`, so a resend with a new timestamp is always a new delivery, while a byte-identical replay only reaches the dedupe layer within `tolerance_seconds` — a `lease_seconds` at or above `tolerance_seconds` therefore leaves no window in which a byte-identical subscription replay can recover a hard-killed dispatch.

## Webhooks: dedupe, dispatch order and log verdicts

Dedupe needs the `wlcm_transactions` table — enable `wlcm.storage.transactions`, off by default. Without it every delivery fires events again, replays included: a default install re-runs your listeners for any identical body resent inside the tolerance window, and, on a multi-worker server, fires them once per concurrent copy of a single delivery.

With it on, both families dedupe: payment deliveries keyed `(connection, payment_id, state)`, subscription deliveries keyed `(connection, hmac_sha256(length_prefixed(external_order_id, event, timestamp), webhook_secret))` — length-prefixed because a bare colon join lets `("A:B", "C")` and `("A", "B:C")` collide, which silently swallowed one of two distinct deliveries — keyed with the connection's `webhook_secret` so the stored key cannot be walked back into the merchant reference it hashes.

A payment redelivery is a duplicate only when the same `state` lands on a row already processed. A different `state` on the same `payment_id` dispatches again only when it moves the payment **forward** — `created` → `initiating` → `paid`/`cancelled`, plus the post-success cancel (-2). A backward move (an `initiating` or `created` notification arriving after a later one) is refused as `stale`: it is a replay, and letting it rewrite the row lets two states ping-pong it and re-dispatch a delivery still in flight. `paid` (2) and `cancelled-during-init` (-1) sit on the same rung, so neither can advance over the other: whichever lands first holds the row, and the other is recorded `stale` with no event. A `paid` arriving after a `cancelled-during-init` therefore does not fire `PaymentSucceeded` — reconcile with `orders()->status($orderId)` if you ever see both for one payment.

A state the package does not model is accepted on a row that has not settled and refused on one that has, and both halves are worth stating outright. On a live row, two *different* unmodelled states each count as moving it forward, so each one claims and dispatches — `WebhookReceived` and the typed non-money events can fire more than once for a single payment while it is still in flight. On a settled row (2 / -1 / -2) an unmodelled state is `stale`: HTTP 200, a `stale` log entry, and **no event at all**, so do not build reconciliation on the assumption that an unrecognised state will always reach your listener. `PaymentSucceeded` fires only on state 2 and a settled row admits nothing but a cancel, so nothing unmodelled can reopen a payment that is already done. Accepting them on a live row is the deliberate half of that trade: refusing what we cannot order would eventually discard a real transition, and a lost payment event is worse than a repeated one your handler is already required to be idempotent about.

Order per delivery is claim row → `WebhookReceived` + typed event → handler → mark processed, or release the claim if anything in that chain throws.

**`processed_at` marks the end of the request, not the end of your work.** A `ShouldQueue` listener returns the instant the job is queued, so the claim is stamped processed while nothing has handled the payment yet — and if that queue never drains, every redelivery is answered `duplicate`, so WLCM's own retries cannot rescue it. The package cannot detect this: once the job is on the queue it has no way to learn whether it ran. If your listeners are queued, treat a stamped `processed_at` as "accepted for processing" and monitor your failed-jobs table as part of your payment reconciliation, or do the money-critical step synchronously and queue only what is safe to lose.

Concurrent copies of one delivery race a single atomic write — a conditional `UPDATE` when a row already exists, an `INSERT` arbitrated by `unique(connection, payment_id)` when one does not: exactly one wins and dispatches, and the losers dispatch nothing. A loser logs `inflight` while the winner is still working, or `duplicate` if the winner finished before the loser looked. `WebhookReceived` is at-least-once per delivery, never once per concurrent copy — and this holds only with `wlcm.storage.transactions` on.

With `wlcm.storage.logs` on, the delivery's log entry carries a `result` recording the verdict:

| `result` | Meaning |
| --- | --- |
| `ok` | claim taken and dispatch began — written before the events fire, so a hard kill still leaves a trace |
| `failed` | a second entry appended when the dispatch threw; an `ok` with no successor is the signature of a worker that died mid-dispatch |
| `duplicate` | already processed |
| `stale` | a payment delivery that does not move the row forward — a backward step, or any state other than a cancel (-2) arriving at a settled row, including one the package does not model |
| `inflight` | another worker holds a live claim on this exact delivery |

**There is a fourth state, and it is no row at all:** if the audit *write itself* fails — dead connection, full disk, lock timeout — the failure is reported through `report()` and swallowed, so the delivery goes on to dispatch and answer 200 with nothing recorded. From the table alone that is indistinguishable from a worker killed before it could write, so **when reconciling a payment you cannot find, check your error tracker as well as `wlcm_logs`** — `report()` is the only place it surfaces. This is a deliberate trade, not an oversight: letting an audit write failure propagate would 500 the delivery, and because WLCM deactivates a webhook after repeated 5xx, a flaky database would take the whole endpoint offline and stop orders being fulfilled. An incomplete audit trail beats an endpoint your database can switch off. The line drawn is transient versus permanent — a write failure is transient and announces itself through `report()`, so it drops; an unreadable `storage.logs` *value* is permanent and silent, so on a delivery it throws instead, releasing the claim so WLCM redelivers.

Subscription deliveries log `ok`, `duplicate`, `inflight` or `failed` — the same set as payments.

## Webhooks: idempotency contract

**Handlers must be idempotent.** A claim takes the row before your handler runs and `processed_at` is still stamped only after it returns. If anything in the dispatch throws, the claim is released on the spot and the next redelivery runs the whole dispatch again — events included. If the worker is killed outright (SIGKILL, OOM, host loss) nothing releases it, and the next redelivery re-runs the dispatch once `wlcm.webhook.lease_seconds` have elapsed; a redelivery arriving inside that window logs `inflight` and dispatches nothing. A handler that runs **longer** than `lease_seconds` can be dispatched twice for the same delivery — size the lease above your slowest handler. At-least-once, not exactly-once. Upgrading past the keyed, length-prefixed `delivery_key` invalidates subscription keys written by an older version, so a redelivery straddling the upgrade may dispatch once more — the same at-least-once contract, not a new hazard.

**Do not rely on webhooks alone.** Live deliveries are effectively at-most-once: failed delivery recorded with `attempts: 1`, no retry scheduled. Reconcile by polling — for orders still pending on your side, call `Wlcm::partner()->orders()->status($orderId)` and settle from `isPaid`/`stateEnum()`. Concurrent copies of a delivery no longer accidentally cover for a dispatch that died, so polling is now the only recovery for a lone hard-killed dispatch, not a second line of defence.

## Webhook handlers and events

Class handler: implement `WlcmWebhookHandler`, set `wlcm.webhook.handler`. A class that exists but does not implement the interface throws `MissingConfigurationException` while handling the delivery; a typo'd class name dies earlier, in the container. Neither fires at boot — both surface on the first webhook.

Subscription webhooks: same route, detected by `event` field. Their signature base is per WLCM docs only, never confirmed against a real delivery — verify before production. Events: `SubscriptionActivated`, `SubscriptionCharged`, `SubscriptionChargeFailed`, `SubscriptionCancelled` (each carries `SubscriptionWebhookPayload`); `WebhookReceived` fires for both families. Own hook: implement `WlcmSubscriptionWebhookHandler`, set `wlcm.webhook.subscription_handler`. `external_user_id`, `subscription_id`, `amount` and `status` sit outside that base too — unauthenticated like the payment `amount`, so reconcile before trusting them.

---

## Onboarding

Token auth, no HMAC. Reached through a separate `OnboardingClient` that holds no key and no secret and owns no signer, so it cannot emit a broken signature for a partner that does not exist yet. `Connection::onboarding()` requires only `base_url`; `Connection::partner()` still requires the full credential set, and that was not relaxed.

**The trailing slash is mandatory.** `/partners/onboarding/` is the onboarding route. Drop the slash and the request resolves to a *different, signed* route which answers `401 {"detail":"Missing or invalid Authorization header"}` — not a 404, so it reads like a credentials problem rather than a path problem. Measured live 2026-07-31. There is a test pinning it.

**The token goes in the query, never the body.** A token in the body answers `400 {"detail":"token_required"}`.

**HMAC headers are ignored, not rejected**, if you send them anyway.

Error codes here are real machine codes, unlike most of this API's prose details, so `errorCode()` and `errorCodeEnum()` both resolve:

| Call | Result |
| --- | --- |
| bogus / expired / empty token on GET | 400 `invalid_or_expired` → `ValidationException` |
| bogus token on POST | 400 `invalid_or_expired` → `ValidationException` |
| token absent on POST | 400 `token_required` → `ValidationException` |
| token absent on GET | 422 with the FastAPI field map → `ValidationException` |
| ip not allowed / partner inactive | 403 → `AuthorizationException` *(from the recovered contract, unverified)* |

**PROVENANCE — read before trusting the success shapes.** Every error path above was probed live on 2026-07-31 with a deliberately invalid token. The two *success* shapes — `validateToken` seeing `{"valid": true}`, and `complete()` returning `{id, name, api_key, api_secret, warning}` — were **not** probed, because no spare onboarding token existed and a successful POST consumes one and mints a real partner. They come from the contract recovered at commit `25a3e47` plus one recorded live sample. Parsing is defensive throughout, so a drifted shape degrades to nulls rather than throwing on a call a merchant cannot repeat. **Whoever first runs this against a real token should confirm the response shape rather than assume it.**

**The minted `api_secret` never reaches a log store.** `Masker::mask` drops `api_secret` and `api_key` before any driver writes, and there is a non-vacuous test — it asserts the response body *was* logged and that the secret is absent from it, so the assertion cannot rot into proving nothing.

### `wlcm:onboard`

```bash
php artisan wlcm:onboard --token= --name= --base-url= --connection=
```

Order is deliberate: **the credentials are printed before anything that can fail.** A `.env` write, a cached config or a failed verify must never be why an operator loses a secret the server shows once. The confirm prompt sits before `complete()`, because that is the call which spends the token — declining costs nothing.

**`.env` policy — append only.** A key that is missing is appended. A key that exists is *never* overwritten: the command prints the current value masked and offers a commented duplicate (`# WLCM_API_KEY=…`). No backup file is written, because a backup would leave a second copy of a live secret on disk. Afterwards it reports which keys were added live, which were commented, and which were left untouched. `EnvWriter` handles `export KEY=`, quoted values, a missing `.env`, a file with no trailing newline, and CRLF. A commented line does **not** count as present — otherwise declining a duplicate once would permanently block that key.

**`--no-interaction` answers every question no.** It will not prompt for a token, will not append commented duplicates, and will not run the verify step. An unattended run cannot grow your `.env` or block on a prompt.

**Under `config:cache` it writes anyway and says so.** The values are inert until `php artisan config:clear` or a rebuilt cache, because the running app keeps the baked ones. The command warns rather than refusing: the credentials are the irreplaceable part, and refusing after the token was spent would be worse than a warning.

**The verify step builds its connection from the fresh values, not from config.** Under a cached config the config path still holds the old credentials, so verifying through it would test the wrong thing and report a false failure.

**A non-default `--connection` prints a config block instead of writing `.env`.** Only the default connection has `WLCM_*` env mappings in the packaged config, so writing `WLCM_API_KEY` while onboarding `shop` would configure `main` and read as success. Nothing is written; the exact `config/wlcm.php` block is printed to paste.

**The token never reaches an exception.** `Masker::redactQuery()` blanks the value of sensitive query parameters on both the exception message and its `requestPath`, at both sites that build one: `PartnerClient`'s transport-failure path and `ErrorMapper`, which handles every mapped status. The second is the one that matters in practice — a bogus token is an ordinary 400, whereas transport failures are rare. Ordinary query values such as `payment_id=179` are left intact, since destroying them would defeat the purpose of the exception context.

---

## Storage: what gets scrubbed

PAN, OTP, passwords, secrets always masked or dropped before the write, whichever driver is in use. Luhn-coincident numeric values (e.g. some ms timestamps) over-mask in log body copies.

The dedicated id columns follow one rule on both tables: only the merchant's own free text is scrubbed — `external_id` on payments, `external_order_id` on subscriptions — because only a merchant can bury a PAN in one. WLCM's own `order_id`, `payment_id` and `transaction_id` are stored byte-intact apart from NUL bytes, which are stripped: Postgres truncates a value at the first NUL and MySQL does not, so leaving them in meant the two engines stored different ids for one delivery. `wlcm_logs` still joins `wlcm_transactions` on them exactly, because both surfaces strip identically.

What the scrub costs is looking a row up by the raw *merchant reference* when that reference is itself a Luhn-passing card-shaped run — 13-19 unbroken digits (roughly one such numeric id in ten), or four groups of four split by spaces or dashes like `1234-5678-9012-3456` — which stores as `123456***3456`. Pass your reference through `Masker::scrubPans()` before the lookup and it matches again.

Full request/response body blobs keep a whole-body scrub regardless, so a PAN the server echoes anywhere still never lands in a body copy.

## Storage: column widths and body reshaping

Database columns are fixed width (`external_id` 100 chars, other ids 64) and a longer value used to throw the insert, losing the whole audit row — values are now cut to the width and end `~` so the cut is visible. The untouched value stays in the log row's `request_body`/`response_body` JSON, which has no width limit — except that NUL bytes are stripped from values and percent-escaped in object keys (`%` becomes `%25` and NUL becomes `%00`, applied while building a fresh array so no two keys can collapse onto one and a merchant key is never overwritten — a key containing a literal `%` is rewritten for the same reason), and the `database` driver alone replaces a body deeper than 64 levels with a single encoded string under a `_wlcm.reshaped` marker.

Nothing strips `_wlcm` from a body, so the marker alone is copyable — what a genuine reshape does is REPLACE the blob, leaving exactly the two keys `_wlcm` and `body`. On `request_body` that shape is conclusive: an inbound delivery mimicking the marker still has to carry the five required fields and the signature it was verified over, and those cannot fit in two keys. On `response_body` it is **not** conclusive and must not be treated as such — a server response has no required fields and no signature, so it can be byte-for-byte the two-key shape without any reshape having happened. Read a `_wlcm.reshaped` marker on an outbound row as a hint, and confirm against the request or the upstream response itself. The reshape exists because MySQL refuses a JSON document over 100 levels deep and the failed write would otherwise leave a dispatched delivery unaudited; the `file` and `log` drivers never cut anything. Lookups by a cut key are cut the same way, so webhook correlation still lands on its own row.

## Storage: the transactions toggle and in-flight claims

`wlcm_transactions` also carries `dispatch_token` and `claimed_at` — the in-flight claim: who is dispatching this delivery and when they took it. Both are cleared when the delivery is processed or the dispatch is released. There is deliberately no command to list or clear a stuck claim; the query is `WlcmTransaction::query()->whereNotNull('dispatch_token')->whereNull('processed_at')`, and a row it returns is either a dispatch running right now or one whose worker was killed — `claimed_at` against `lease_seconds` is what separates them.

The suppressed copy leaves no trace of its own on a default install: `inflight` is recorded only as the `result` of an audit log entry, and `storage.logs` is off unless you turn it on.

`transactions` is a strict toggle: `true`/`'1'`/`1` on, `false`/`'0'`/`0`/`null`/`''` off, anything else throws `MissingConfigurationException` on the first recorded call — `'off'` is a typo, not an off switch.

## Storage: log drivers

All three drivers record the same fields per entry — the `wlcm_logs` columns; outgoing entries carry the request/response ones, webhook entries only the delivery ones. A subscription delivery files its `external_order_id` under `external_id`, the same column `wlcm_transactions` puts it in, and leaves `order_id`/`payment_id` null — those name payment-family ids, and `subscription_id` in either would collide with one.

The file driver appends with `LOCK_EX`, creates the directory if missing, and timestamps in ISO-8601. A relative `WLCM_LOGS_PATH` resolves against the process working directory — different for web, queue workers and CLI — so give it an absolute path. A typo'd `logs_channel` does not throw: Laravel falls back to its emergency logger and the entries land in `storage/logs/laravel.log` instead of the channel you named. Check the channel name first when entries go missing. Anything else throws `MissingConfigurationException` the first time the store resolves — a typo'd driver name fails loudly instead of silently dropping the audit trail. `null`, `''` and `'0'` count as off, so neither an unset env var nor `WLCM_LOGS=0` is a typo.

The driver is picked once, when the store is first resolved — it is a singleton, so swapping between drivers at runtime needs a rebind to take effect: entries keep going to the driver that was resolved, not to the newly named one. They are not lost, which they were until recently — each driver used to ask "am I the configured driver", so a flip in a long-lived process (Octane, a queue worker, a warm container) silently sent every entry nowhere: no rows, no bytes, no error, no `report()`, delivery still 200. Turning logging off and back on at runtime keeps working: every driver rechecks the on/off state on each write.

A value it cannot read behaves **differently on the two paths, deliberately**: an outbound call reports the config error and drops the entry, because the payment has already completed at WLCM and destroying that call would leave you unable to tell a failed payment from a broken audit setting; a webhook delivery **throws**, because nothing has happened upstream yet, so the claim is released and WLCM simply redelivers. That asymmetry exists so a money event can never fire with no audit row behind it. What is deliberately *not* offered is a dispatcher that picks the driver per write — it would make runtime flips take effect and would also let a hand-constructed second driver stay silent, but it costs `app(LogStore::class)` returning your configured driver rather than a wrapper, which is a documented contract. The trade is recorded here so it does not have to be rediscovered: if runtime driver switching ever matters more than that contract, a dispatcher is the shape to build.

## Storage: pruning

`prune_days` is the age window over every `wlcm_logs` row and over the `wlcm_transactions` rows that already carry `processed_at` — an in-flight delivery is never pruned out from under the dedupe check. Null or non-numeric switches that window off: `wlcm_logs` then prunes nothing at all, while `wlcm_transactions` still answers to `stale_days`. Numeric values clamp to a floor of 1 day. Keep `prune_days` above WLCM's redelivery window — a pruned row is a forgotten delivery, and a replay after it re-runs your handlers.

`stale_days` is a second, independent window over `wlcm_transactions` — off by default. It reaps only abandoned checkouts: rows no webhook ever claimed, so `processed_at`, `payment_id` and `delivery_key` are all null. A claimed row still holding `processed_at` null is a delivery that crashed mid-dispatch, and neither window reaps it at any setting — reaping one makes WLCM's next redelivery look fresh and re-runs your handlers. `dispatch_token` and `claimed_at` are never prune predicates. Reaping an abandoned checkout costs only the checkout-time linkage: a late webhook for it still dispatches, as a new row.

The two windows disable and clamp independently; a prune run applies whichever are set. Pruning covers those two tables only. The file driver's JSONL grows forever — rotate it yourself; the `'log'` driver inherits whatever retention its channel has. A failing audit sink is swallowed and `report()`ed so it never breaks a payment call — but if the app's default log channel is broken too, `report()` itself can throw and surface to the caller. Keep that channel healthy.

## Storage: rebinding the stores

Log writes go through the `Uzbek\Wlcm\Storage\LogStore` interface; rebind it to send audit entries somewhere none of the shipped drivers reach. Its `webhook()` takes a fourth `array $ids` argument carrying the correlation columns off the raw delivery — server ids byte-intact, merchant reference scrubbed — because the blob it is handed is whole-body scrubbed and that would mangle a Luhn-coincident `order_id`; omit it and the store falls back to reading the blob. An implementation written against the three-argument signature will not satisfy the interface.

Transaction rows go through `Uzbek\Wlcm\Storage\TransactionStore` (shipped binding: `TransactionRecorder`); rebind it to keep checkout and webhook-dedupe state outside `wlcm_transactions`. **A rebound store must decide the claim by the affected-row count of one atomic statement; one that decides by reading `processed_at` reintroduces concurrent duplicate dispatch, and nothing in the type system will tell you.**

Both bindings are singletons handed to the manager at construction — rebind before the first `Wlcm::` call of the process, or the **outbound** clients keep the old one. That caveat is one-sided, and the asymmetry is worth knowing because it splits your audit trail: the webhook controller is resolved per request and constructor-injects whatever is bound at that moment, so a **late rebind is ignored outbound and honoured inbound**. Rebind late and outgoing calls keep landing in the old sink while webhook deliveries go to the new one. Rebind before the first `Wlcm::` call and both agree. Binding order relative to the provider does not matter: the package registers with `singletonIf`, so a store you bind before its provider runs — `Application::configure()->withSingletons([...])` — is kept rather than overwritten.

## Caller-opened database transactions

Neither a webhook claim, nor `checkout()->create()`, nor `orders()->create()`, nor `orders()->generateLink()` runs inside a caller-opened database transaction: all are refused with `CallerTransactionException` while `wlcm.allow_caller_transaction` is `false`, the default.

The row rolls back and the consequence does not — a claim taken inside your transaction vanishes with it while the events it guarded have already fired in process, so the next redelivery fires them again; a checkout created inside it leaves WLCM holding a real payment with no row linking it to your order. The guards run *before* the request leaves the process, so a refusal means nothing was sent — the message says so, because a throw from a payment call otherwise reads as the worst case and invites a double submit.

Setting the key `true` restores the previous behaviour exactly, and is what you want if your own middleware wraps requests in a transaction — but then both hazards are yours, along with the original one: every claim joins your transaction, so concurrent writers block instead of losing the race and no claim commits until it ends. The guard is consulted only when `wlcm.storage.transactions` is on; with no row to lose there is nothing to protect.

Two residuals, deliberately: the other state-changing calls — subscription create, split, fiscal register — can still lose their `wlcm_logs` audit row to a caller rollback, which is audit loss rather than money loss and is **not** covered by this flag (if a money hazard is ever measured on one of them, the fix is to widen this guard, not to add a second one); and **a rebound `TransactionStore` bypasses the inbound half entirely**, because the guard lives at claim time inside the shipped recorder — your `checkout()` calls are still refused inside an open transaction, your webhook claims are not. If your store keeps a dedupe row, apply the same rule in it.

---

## Verify on production

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
- **Order `status` vocabulary** — `orders()->create()` returns a string `status`; only `created` has ever been observed. `OrderState` has one case and `statusEnum()` returns null for anything else

Sandbox-resolved (2026-07): `me` path is `/partners/me` (docs' `/integrations/me` 404s); webhook secret = per-webhook `secret_key` chosen at create; OTP confirm path is `/integrations/payment/card/confirm` (sandbox console's `/integrations/card/confirm` has no route).

## Sandbox

Base URL: `https://apidev.wlcm.uz/api/v1`. `mock_payme` — sandbox-only provider, pass raw string (`provider: 'mock_payme'`); not in `PaymentProvider` enum, invalid in production. Hosted test page: any card number, straight to OTP. Active: `payme`, `click`, `mock_payme`. Inactive: `uzum`, `paylov`, `card`. Fiscal register returns HTTP 500 even for paid payments — sandbox OFD unwired. Subscription `attachCard` fails `card_not_found` (no Paylov test cards) — `activate`/`charge` untestable end-to-end.

## Testing details

`fake()` fills `base_url`, `api_key`, `api_secret`, `webhook_secret` on the default connection with dummies, but only where the value is missing or empty — a configured value is never clobbered. Call it before the first `Wlcm::` call of the test: the manager caches the `Connection` and the client captures its config at construction, so a `fake()` running after the client exists leaves that client on the old config.

Unstubbed endpoints throw. The catch-all answers HTTP 500, so `ServerException` comes out with message `wlcm stub missing for this endpoint` and `$e->requestPath` naming the URL — nothing success-shaped to mistake for a real answer. `fake()` installs that catch-all as a global `Http::fake()` `*`: it stubs every outbound HTTP call in the app, not just WLCM's, so anything else your test talks to over HTTP gets that 500.

The filled `webhook_secret` is the published constant `whsec_fake`, so webhook tests running under `fake()` verify signatures against a value printed in this repository — they prove wiring, not secrecy. Set a real secret in config before calling `fake()` when the secret is what you are testing.

Both order endpoints carry default stubs, and each has its own assertion:

```php
$fake = Wlcm::fake();

Wlcm::partner()->orders()->create(amountTiyin: 250000, externalId: 'ORD-7');

$fake->assertOrderCreated(fn (array $payload): bool => $payload['external_id'] === 'ORD-7');
$fake->assertLinkGenerated(fn (array $payload): bool => $payload['payment_provider'] === 'payme');
```

`assertOrderCreated`, `assertLinkGenerated` and `assertCheckoutCreated` all match on the request path's **suffix**, not a substring — otherwise a read of `/orders/{id}/status` would satisfy an assertion about `/integrations/orders`.
