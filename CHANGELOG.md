# Changelog

All notable changes documented here. Format: keep-a-changelog. Semver.

## [Unreleased]

### Added
- `orders()->create()` registers an order before a payment provider is chosen, and records it to `wlcm_transactions` with no provider, no integer state and no payment id
- `orders()->generateLink()` attaches a provider to an existing order and returns its checkout URL, addressing the order by `orderId` or `externalId`. Repeatable — a second call switches provider, last call wins — and it completes the audit row `create()` wrote rather than adding a second. `checkout_url` is derived from the order id, not the provider, so it does not change when the provider does

### Changed
- BREAKING: `TransactionStore` gains `recordOrder(string $connection, array $requestPayload, Order $order)` and `recordOrderLink(string $connection, string $provider, OrderLink $link)`. `TransactionStore` is a documented rebinding point, so an implementation written against the previous interface will fatal on upgrade

### Notes
- A provider-agnostic checkout link is not possible: `payment_provider` is mandatory on the link endpoint and WLCM hosts no provider-selection page, so a single QR code deferring the choice cannot be built against this API. Render your own chooser and call `generateLink()` with the payer's pick

## [0.1.0] - 2026-07-27

### Added
- Partner API client with HMAC request signing, multiple named connections and a `Wlcm::partner()` facade
- Checkout (redirect and card), OTP confirm, payment cancel, and the public order-status and providers endpoints
- Subscriptions: create, get, find by external order id, charge, cancel, plus the unsigned card attach/confirm/activate flow
- Payment splits, percent and fixed, with a `split_amount_unit` switch for the fixed-amount wire unit
- Fiscalization: receipt lookup, register, refund
- Webhooks resource — list, create, update, delete, delivery history
- Auto-registered signed webhook route with payment and subscription events, plus a configurable class handler for each family
- Replay protection: a timestamp freshness window, and dedupe on `wlcm_transactions` keyed by payment state or subscription delivery
- Optional audit trail on three drivers — the `wlcm_logs` table, a JSON-lines file, or a Laravel log channel
- Retention: `prune_days` over both tables and a separate `stale_days` reaper for abandoned checkouts
- `Wlcm::fake()` — dummy credentials, stubbed endpoints, a loud 500 catch-all for anything unstubbed, and checkout assertions
- Typed exceptions carrying the server's machine error code, with validation failures exposed as a field map
- Amounts are `int` tiyin throughout, converted to sum only where the API expects it

### Changed
- BREAKING: `TransactionStore::applyWebhook`, `markProcessed`, `claimSubscription` and `markSubscriptionProcessed` take a `string $token`, and `releaseClaim`/`releaseSubscriptionClaim` are new. A rebound store must decide the claim by an affected-row count, never by reading `processed_at`
- BREAKING: a webhook state moving *backward* on the same `payment_id` is now refused as `stale` instead of dispatching again
- A required webhook field that is present but empty is now a 422, matching a missing one
- BREAKING: `LogStore::webhook()` takes a fourth `array $ids` argument, mirroring `out()`. `LogStore` is a documented rebinding point, so an implementation written against the three-argument signature will fatal on upgrade
- A dispatch that throws now appends a second audit entry with `result = 'failed'`; `ok` means the claim was taken and dispatch began, so an `ok` with no successor is a worker that died mid-dispatch
- BREAKING: `wlcm.allow_caller_transaction`, default `false`, refuses a webhook claim or a `checkout()->create()` made while the caller holds an open database transaction. An app whose middleware wraps requests in one must set it `true`, which restores the previous behaviour exactly. Only consulted when `wlcm.storage.transactions` is on
- BREAKING: a subscription `external_order_id` may no longer contain a colon, and a payment `state` must be numeric. A legitimate subscription whose reference contains a colon is now refused with 422 — deliberate, and the cost of closing the cross-family signature collision in both directions
- Partner info reads `/partners/me`; the documented `/integrations/me` has no route
- Card OTP confirm posts to `/integrations/payment/card/confirm`
- Fixed split amounts ship as SUM by default — the server compares fixed totals against the payment's SUM value, though the docs claim tiyin
- A failed subscription charge is HTTP 200 data, never an exception — guard every charge with `SubscriptionChargeResult::succeeded()`

### Documented
- A late `LogStore`/`TransactionStore` rebind is ignored outbound but honoured inbound, so it splits the audit trail across two sinks. The README claimed the outbound behaviour for both paths
- A rebound `TransactionStore` does not get the inbound half of the caller-transaction guard, which lives at claim time in the shipped recorder
- `processed_at` marks the end of the request, not the end of a queued listener. A queue that never drains leaves the payment marked processed and every redelivery answered `duplicate`
- `php artisan config:cache` freezes `env()`, so rotating a webhook secret in `.env` alone is inert — the old baked secret keeps verifying while the new one 403s. `route:cache` reads the cached config, so rebuilding routes without rebuilding config bakes a stale `wlcm.webhook.path`
- Why there is no per-write driver dispatcher for `storage.logs`, and what building one would cost

### Fixed
- Malformed success responses throw instead of hydrating into zeroed DTOs; non-array split rows are dropped rather than zeroed
- Amounts are validated end to end — non-positive, non-integer, indivisible and oversized values throw instead of shipping the wrong number
- Webhook deliveries missing a required field, or carrying an unreadable timestamp, are rejected instead of coerced
- A settled payment row accepts only a subsequent cancel — every other late delivery, including a state the package does not model, is recorded stale instead of replayed
- Concurrent identical webhook deliveries dispatched the money-path event up to once per copy (deterministically once per copy on the subscription path). A delivery is now claimed by one atomic write whose affected-row count is the verdict — a conditional `UPDATE`, or an `INSERT` arbitrated by the unique index on a cold race — so exactly one copy dispatches and the losers log `inflight`, or `duplicate` if the winner already finished
- A slow dispatch could stamp a *later* delivery's claim as processed, and that later delivery's redelivery was then answered `duplicate` — its event never fired again. Claims carry a per-attempt token and a dispatch can only stamp its own
- The two unique indexes on `wlcm_transactions` are named explicitly. Laravel's derived `wlcm_transactions_connection_delivery_key_unique` is 48 characters before any prefix, so a 17-character `DB_PREFIX` broke `php artisan migrate` with MySQL error 1059 and a tenant-prefixed host could not install the audit tables at all; Postgres truncated at 63 instead, where two long prefixes could collide
- `MissingConfigurationException` now names the accepted values and how to recover. A value it rejects, baked into `bootstrap/cache/config.php`, 500s every request *and* breaks `php artisan config:clear`, `list` and `serve` — deleting the cache file by hand is the only way out, and the message never said so
- Changing `wlcm.storage.logs` from one driver to another in a long-lived process sent every audit entry nowhere — no rows, no bytes, no error, delivery still 200 — because each driver gated on being the *configured* driver rather than on logging being on. The resolved driver now keeps writing. A value edited into something unreadable is handled per direction: an outbound write reports and drops it, so a completed payment call is never destroyed by a broken audit setting, while a webhook delivery throws, so the claim is released and WLCM redelivers rather than a money event firing with no audit row
- A host binding `LogStore` or `TransactionStore` *before* the package provider registered — the `Application::configure()->withSingletons([...])` shape — was silently overwritten, so its audit sink never received anything. All three container bindings are now `singletonIf`
- The host-route conflict watch missed almost every way a host can take the endpoint. It compared URI strings over `RouteCollection::getRoutes()`, which is keyed by method *list*, while route matching is keyed by method and URI — so a host `Route::any()`, `match()`, `redirect()`, `permanentRedirect()`, a domained route, or a route whose parameter captures the path all took the endpoint while the package's own route survived in the scanned table as a phantom still carrying the middleware, and the watch stayed silent. Measured over eight host shapes in both registration orders: 7 silent misses and 4 false alarms, 5 of 16 cells correct. The watch now resolves which route would actually serve a `POST`, first-match-wins over the same table dispatch uses, and the path comes from `wlcm.webhook.path` rather than from our own route, which a baked table need not contain
- **The watch was completely inert under `php artisan route:cache`** — the standard production deploy — so it was alive in development and dead where it mattered. It ran from an application `booted()` callback, and with routes cached the compiled table is installed by a `require` that `RouteServiceProvider` queues one level deeper into the same queue, therefore later: the watch walked a table that was still empty and its own route file had not been loaded either. Measured in a real application against a table baked exactly as `route:cache` writes it: live reported the collision, cached reported nothing while serving unverified deliveries. The watch now also looks after the final bootstrapper, once the table is installed, and takes the report once from whichever point sees it first
- Domained host routes were reported only when their URI matched ours **character for character, parameter name included**, because the probe requests were built for host `localhost` and `HostValidator` rejects every real domain. Inside a `Route::domain()` group — the normal shape for an API subdomain — a renamed parameter, a required parameter, a bare path with no parameter, an `any('{any}')` catch-all, a `wlcm/{rest}` wildcard and any tenant-patterned domain all served unverified deliveries in silence, while the identical route *without* a domain was caught; and `RouteCollection::get($method)` returns domained routes first, so they outrank ours on their host. Domained routes are now tested by URI capture with the domain deliberately ignored, since the delivery host is unknowable at boot: any domained POST route that captures the path without the middleware is reported, which over-reports for hosts WLCM never posts to and is the intended trade. Two narrower silences went with it — a host route pinned to any connection but the first configured one, because the probe set carried only `connections[0]`, and an `https`-only route, because both probes were `http`
- Probing the route table froze the compiled regex of every route it touched. `Route::$compiled` is written once and nothing invalidates it, so a constraint a route acquired after boot was ignored at dispatch and an unconstrained regex accepted what it should have refused. The watch now compiles throwaway copies and never the live routes
- `wlcm.webhook.enabled = false` did not close the endpoint under `php artisan route:cache`: the table was baked while it was on, boot never re-checked, and a process reporting `enabled: false` accepted signed deliveries and fired events. The toggle is now re-read on every delivery and answers 404 when off
- An offsetless datetime `timestamp` was parsed in the app's timezone, so any host not running UTC read every delivery a whole offset out and 403'd it as stale — a lost delivery on a setting unrelated to WLCM. Offsetless timestamps are now read as UTC; offset-bearing ones keep their own
- A webhook handled inside a host transaction that rolled back fired the money event twice: events dispatch in process and cannot be rolled back, the claim can, so the first delivery committed no rows and the redelivery dispatched again. A checkout made the same way left WLCM holding a real payment with no `wlcm_transactions` row at all. Both are now refused unless `wlcm.allow_caller_transaction` is set, and the checkout refusal happens before the request is sent
- A backward state transition on one `payment_id` re-dispatched a delivery still in flight; the ladder is now forward-only
- A webhook whose signature was verified over the raw body while the dispatched payload came from `$request->all()` could be forged wholesale: under a non-JSON content type those are two different documents, so a captured signature authenticated the body while the query string supplied `order_id`, `payment_id`, `amount` and `external_id`. Everything now derives from one document
- A captured payment delivery could be replayed as a subscription with its signature unchanged, because both signature bases are bare colon joins; a colon in `event` or `external_order_id` is now refused. The reverse also held — a genuine subscription signature replayed as a payment — and is closed by refusing a colon in `order_id`/`payment_id` and a non-numeric `state`
- A negative `amount` stored as a negative money value on PostgreSQL while MySQL rejected the write, and an unparseable one threw inside the claim — 500 with no audit row at all, on a delivery WLCM does not retry. Both are now a 422 from the same guard, before any claim exists
- A body deeper than 64 levels is reshaped before the audit write — MySQL refuses a JSON document over 100 levels deep and the swallowed failure left a dispatched delivery with no audit row at all
- NUL bytes are stripped from stored ids and logged values, and percent-escaped in logged object keys, rebuilt into a fresh array so no two keys collapse onto one: PostgreSQL truncates a value at the first NUL and errors on JSON access to one, while MySQL stores it, so the two engines disagreed about what a delivery said
- Two distinct subscription deliveries whose reference or event contained a colon produced the same `delivery_key` and the second was swallowed as a duplicate with no events fired; the preimage is now length-prefixed and injective
- The webhook signature was verified against framework-parsed input, so a stock app's `TrimStrings` could make a correctly signed delivery fail verification with 403; it is now verified against the raw request body
- Classification reads are pinned to the write connection: on a read/write split they could previously miss a just-committed claim on a lagging replica and either suppress the dispatch outright or 500 the delivery
- Audit write *failures* — the sink itself erroring — are reported and swallowed on both directions so they never break a payment call, which means a dispatched delivery can leave no audit row at all; `report()` is where that surfaces, and it is chosen over 500ing the delivery because WLCM deactivates a webhook after repeated 5xx, so a flaky database would otherwise take the endpoint offline. If the app's default log channel is broken too, `report()` itself can throw and surface to the caller — the two failures are correlated, since one unwritable `storage/` or dead log stack breaks the sink and the reporting channel together. Distinct from an unreadable `storage.logs` *value*, which reports and drops outbound but throws on a webhook delivery so the claim is released rather than a money event firing unaudited
- An unknown log driver, a bad tolerance value or a missing secret throws on use instead of silently disabling the feature

### Security
- A PAN split by a NUL byte (`4111111111\0111111`) was logged in the clear: the Luhn scan never saw it as one number. Stripping NUL before the scan closed a masking bypass
- Secrets, tokens and signatures dropped, PANs truncated, OTPs and passwords starred before anything reaches a log store
- Luhn-gated PAN scrub over free text as well as keyed fields — exception messages, error bodies and webhook payloads
- Webhook signatures verified before any listener runs; an invalid signature is a 403 and nothing dispatches

### Removed
- OAuth2 Subscribe module — built from obsolete docs; WLCM confirmed that API is dead
- Partner onboarding support

### Notes
- `amount` and `external_id` sit outside the webhook signature base — reconcile against order status before fulfilling
- Fixed split unit pending final WLCM confirmation; a wrong `split_amount_unit` misplaces money by a factor of 100
- `confirmPaylov` body casing comes from the sandbox console and is unverified against a live call
- The subscription webhook signature base follows WLCM docs only, never confirmed against a real delivery
