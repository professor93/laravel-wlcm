# Changelog

All notable changes documented here. Format: keep-a-changelog. Semver.

## [Unreleased]

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

### Fixed
- Malformed success responses throw instead of hydrating into zeroed DTOs; non-array split rows are dropped rather than zeroed
- Amounts are validated end to end — non-positive, non-integer, indivisible and oversized values throw instead of shipping the wrong number
- Webhook deliveries missing a required field, or carrying an unreadable timestamp, are rejected instead of coerced
- A settled payment row accepts only a subsequent cancel — every other late delivery, including a state the package does not model, is recorded stale instead of replayed
- Concurrent identical webhook deliveries dispatched the money-path event up to once per copy (deterministically once per copy on the subscription path). A delivery is now claimed by one atomic write whose affected-row count is the verdict — a conditional `UPDATE`, or an `INSERT` arbitrated by the unique index on a cold race — so exactly one copy dispatches and the losers log `inflight`, or `duplicate` if the winner already finished
- A slow dispatch could stamp a *later* delivery's claim as processed, and that later delivery's redelivery was then answered `duplicate` — its event never fired again. Claims carry a per-attempt token and a dispatch can only stamp its own
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
- Audit write failures are reported and swallowed so they never break a payment call
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
