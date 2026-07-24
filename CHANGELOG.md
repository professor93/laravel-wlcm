# Changelog

All notable changes documented here. Format: keep-a-changelog. Semver.

## [Unreleased]

### Added
- Partner API client: checkout, OTP confirm, split, fiscalization, me
- Webhook route with signature verification, events, configurable handler
- Optional wlcm_logs / wlcm_transactions tables with masking and pruning
- Wlcm::fake() testing helper
- Providers resource (public providers list) and Orders resource (public order status with tiyin-safe amount accessor)
- Payments::cancel (integer reason code) and Payments::confirmPaylov (unverified: camelCase body is sandbox-console-derived)
- Webhooks CRUD resource (list/create/update/delete/delivery events) with signed PATCH/DELETE support
- Subscriptions resource: create/get/findByExternalOrderId/charge/cancel plus unsigned end-user attachCard/confirmCard/activate; failed charges surface as data on SubscriptionChargeResult
- Subscription webhook support: SubscriptionWebhookPayload, SubscriptionActivated/Charged/ChargeFailed/Cancelled events, wlcm.webhook.subscription_handler config with WlcmSubscriptionWebhookHandler interface
- X-Webhook-Signature header accepted when the webhook body has no signature field
- AuthenticationException::errorCode() (invalid_api_key / invalid_signature / timestamp_expired)
- FastAPI validation error mapping: ValidationException::errors() field map with readable messages
- PaymentState::CancelledDuringInit (-1); subscription and paylov codes on WlcmErrorCode
- Tiyin::fromSumString for inbound SUM decimal strings
- ConflictException mapped to HTTP 409 (live: double split)
- errorCode on the WlcmException base — every thrown exception carries the server's machine code (from the code field or a bare snake_case detail string)
- partner.split_amount_unit config: wire unit for fixed split amounts ('sum' default; 'tiyin' passes raw) — sandbox-confirmed SUM, tiyin variant retained, pending final WLCM confirmation (2026-07-25)
- OrderStatus paid-shape fields: paymentId, gateway, transactionId, performedAt; state falls back to status_explanation.state
- WebhookInfo events/lastTriggeredAt/lastStatusCode; Webhooks::events() unwraps the paginated history envelope
- Real captured webhook delivery vector as a regression test (body-only signature, JSON int fields)

### Changed
- Partners me path corrected to /partners/me (sandbox-verified; /integrations/me 404s)
- PartnerInfo reshaped to the real response (no uuid/api_keys; company, contact, flags, raw passthrough); ApiKeyInfo removed
- PaymentState::Pending renamed to Initiating to match server naming
- Checkout expectations aligned with reality: HTTP 200, created order state 1, all response keys always present
- Payments::split converts fixed-split tiyin input per split_amount_unit; percent values untouched
- Subscriptions::cancel returns the full Subscription object (live-verified)
- SubscriptionChargeResult::isSuccess renamed to succeeded — a failed charge is HTTP 200 data, never an exception; always guard with succeeded()

### Removed
- OAuth2 Subscribe module (Wlcm::subscribe(): cards, receipts, holds, p2p, service payments, split, fiscalization, auth) — built from obsolete docs.wlcm.uz/subscribe/* pages; WLCM confirmed that API is dead
- Partner onboarding support (validateOnboardingToken/completeOnboarding, OnboardingCredentials) — not needed

### Notes
- wlcm_transactions records partner checkout + webhook state in v1
- Signer canonical-path multi-param sorting confirmed live on sandbox (both URL param orderings authenticate)
- Split fixed-amount unit sandbox-confirmed SUM (3 live signals: server compares fixed totals to the payment SUM value); docs claim tiyin; pending final WLCM confirmation 2026-07-25
- Documented server-side split rules: fixed total must equal the payment amount exactly; duplicate recipient 422; unknown recipient 404; validation order duplicate -> total -> existence
- Fiscal item amounts are passed through unchanged (per docs tiyin; the SDK does not convert them)
