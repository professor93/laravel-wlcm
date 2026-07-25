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
- Partner info reads `/partners/me`; the documented `/integrations/me` has no route
- Card OTP confirm posts to `/integrations/payment/card/confirm`
- Fixed split amounts ship as SUM by default — the server compares fixed totals against the payment's SUM value, though the docs claim tiyin
- A failed subscription charge is HTTP 200 data, never an exception — guard every charge with `SubscriptionChargeResult::succeeded()`

### Fixed
- Malformed success responses throw instead of hydrating into zeroed DTOs; non-array split rows are dropped rather than zeroed
- Amounts are validated end to end — non-positive, non-integer, indivisible and oversized values throw instead of shipping the wrong number
- Webhook deliveries missing a required field, or carrying an unreadable timestamp, are rejected instead of coerced
- A terminal payment row accepts only a subsequent cancel; other late deliveries are recorded stale instead of replayed
- Audit write failures are reported and swallowed so they never break a payment call
- An unknown log driver, a bad tolerance value or a missing secret throws on use instead of silently disabling the feature

### Security
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
