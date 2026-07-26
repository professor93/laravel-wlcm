<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Uzbek\Wlcm\Exceptions\InvalidAmountException;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Support\ConfigToggle;
use Uzbek\Wlcm\Support\ConnectionConfig;
use Uzbek\Wlcm\Support\Tiyin;

/**
 * @phpstan-import-type WebhookPayloadShape from WebhookPayload
 * @phpstan-import-type SubscriptionWebhookPayloadShape from SubscriptionWebhookPayload
 */
final readonly class VerifyWebhookSignature
{
    private const array REQUIRED_PAYMENT = ['order_id', 'payment_id', 'amount', 'state', 'timestamp'];

    private const array REQUIRED_SUBSCRIPTION = ['external_order_id', 'event', 'timestamp'];

    // Epoch seconds, epoch milliseconds, or an ISO-ish datetime. Anchored, so nothing can
    // be glued in front of it.
    private const string TIMESTAMP_SHAPE = '/^(?:\d{10}|\d{13}|\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)$/';

    public function __construct(private Repository $config) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Per request, not only at boot. The provider skips loadRoutesFrom when the toggle
        // is off, but `php artisan route:cache` bakes the table while it is ON and boot
        // never runs that check again: the served process reported `enabled: false` and
        // went on accepting signed deliveries, events and all, until someone ran
        // route:clear. A security toggle a routine deploy step can silently disarm is not
        // a toggle. 404 because a disabled endpoint does not exist, which is what an
        // unrouted path already answers.
        if (! ConfigToggle::enabled($this->config->get('wlcm.webhook.enabled', true), 'wlcm.webhook.enabled')) {
            abort(404);
        }

        // ONE document. The signature base, every guard, the freshness check, the payload
        // dispatched and the blob logged all derive from these same bytes. Verifying one
        // document and acting on another is an authentication bypass, and splitting them
        // produced exactly that: under a non-JSON content type `$request->all()` is the
        // QUERY STRING while `getContent()` is still the json body, so a captured
        // signature authenticated the body while the query decided what got dispatched.
        //
        // Keyed on the request's ENCODING, not on whether the body happens to parse — a
        // json body sent as text/plain parses perfectly, which is how the split was
        // reachable. Anything not declared json falls back to the framework-parsed input,
        // which keeps the pre-existing behaviour rather than inventing a refusal.
        $decoded = $request->isJson() ? json_decode($request->getContent(), true) : null;
        /** @var array<string, mixed> $data */
        $data = is_array($decoded) ? $decoded : (array) $request->all();

        // Subscription events carry `event` field; payment webhooks never do. Shared with
        // WebhookLogIds so the audit trail cannot disagree with the router about which
        // family a delivery belongs to.
        $isSubscription = WebhookFamily::isSubscription($data);

        foreach ($isSubscription ? self::REQUIRED_SUBSCRIPTION : self::REQUIRED_PAYMENT as $field) {
            $value = $data[$field] ?? null;

            // Empty counts as missing. An empty payment_id coerces to '' and every such
            // row collides on unique(connection, ''), where a claim would answer the
            // second, genuinely distinct delivery Duplicate and swallow it.
            if ($value === null || ! is_scalar($value) || (string) $value === '') {
                abort(422, "Missing field: {$field}");
            }
        }

        // A payment base {order_id}:{payment_id}:{state}:{timestamp} and a subscription
        // base {external_order_id}:{event}:{timestamp} are both bare colon joins, so a
        // captured payment delivery can replay as a subscription with its signature
        // UNCHANGED.
        //
        // These two guards do NOT close the family on their own, and the derivation that
        // said they did was wrong three times running. It assumed the replayed
        // subscription reuses the captured timestamp; `timestamp` is attacker-supplied, so
        // the real equation is `X:E:T' == O:P:S:T` and it has a field-aligned solution
        // with both of these fields clean — X=order_id, E=payment_id, T'="state:timestamp".
        // What closes that one is the timestamp SHAPE guard further down. Coverage is
        // measured by a generated enumeration over every split, not argued here.
        //
        // Not keyed on `subscription.` naming: observed is not exhaustive, and refusing an
        // unfamiliar-but-legitimate event kind would lose a real payment event. The cost
        // is that a merchant reference may not contain a colon — there is no escape for
        // that field, so it is documented rather than worked around.
        //
        // Scalar-gated: a non-scalar keeps its "Missing field" answer from the loop above.
        foreach (['event', 'external_order_id'] as $field) {
            $value = $data[$field] ?? null;

            if ($isSubscription && is_scalar($value) && str_contains((string) $value, ':')) {
                abort(422, "Invalid field: {$field}");
            }
        }

        // The SAME ambiguity runs the other way, and the guard above only constrains the
        // subscription family. A genuine subscription base `A:B:subscription.charged:ts`
        // is byte-identical to a payment base, so it replays as a payment with its
        // signature untouched.
        //
        // Two guards, because either alone is insufficient. `state` must be numeric:
        // WebhookPayload already casts it with (int), so a non-numeric state is
        // meaningless by construction, and that alone closes the observed replay. But
        // where the timestamp carries colons there are readings whose third segment IS
        // numeric — and in every one of those a colon lands in order_id or payment_id,
        // which are WLCM-minted and never legitimately contain one. Together they close
        // every reading enumerable from a subscription base WLCM would actually sign.
        //
        // NOT claimed as closing the family: a hypothetical numeric, colon-free event
        // paired with a colon-bearing timestamp would still reconstruct. The real fix is
        // domain separation in both bases, which is WLCM's wire format, not ours.
        if (! $isSubscription) {
            foreach (['order_id', 'payment_id'] as $field) {
                $value = $data[$field] ?? null;

                if (is_scalar($value) && str_contains((string) $value, ':')) {
                    abort(422, "Invalid field: {$field}");
                }
            }

            $state = $data['state'] ?? null;

            if (is_scalar($state) && ! is_numeric((string) $state)) {
                abort(422, 'Invalid field: state');
            }
        }

        // The timestamp must LOOK like a timestamp, checked here and not left to the
        // freshness window. Both bases are colon joins and `timestamp` is the trailing
        // field, so an unconstrained one can absorb `"{state}:"` and let a payment base be
        // re-read as a subscription with BOTH colon-guarded fields clean:
        //
        //   X="1254" E="7788" T'="2:2026-07-24 12:00:00"  ==  1254:7788:2:2026-07-24 12:00:00
        //
        // Freshness happens to reject that shape, but only when tolerance is enabled, and
        // null tolerance is supported — a guard that holds only because a second check
        // catches the leftovers is not a guard. Pinning the FORMAT fixes the trailing
        // field's colon count instead of leaving it attacker-chosen.
        //
        // Shapes accepted are the ones WLCM produces: 10/13-digit epochs and ISO-ish
        // datetimes with an optional T, fraction and offset. Deliberately wider than the
        // three the fixtures use, so an unobserved-but-legitimate format is not refused —
        // the same mistake as keying a guard on `subscription.` naming.
        $timestamp = $data['timestamp'] ?? null;

        if (is_scalar($timestamp) && preg_match(self::TIMESTAMP_SHAPE, (string) $timestamp) !== 1) {
            abort(422, 'Invalid field: timestamp');
        }

        // `amount` rides outside the signature base by WLCM's design, so one captured
        // delivery lets anyone set it freely. A negative reaches an unsignedBigInteger
        // column that mysql rejects with a 500 and postgres accepts — storing negative
        // money and firing the events on the same input mysql refuses. Refused here, on
        // both engines, before any claim can exist. Refusing beats clamping: a silently
        // corrected money value is worse than a delivery someone can be asked to resend.
        $amount = $data['amount'] ?? null;

        if (! $isSubscription && is_scalar($amount)) {
            if (str_starts_with(ltrim((string) $amount), '-')) {
                abort(422, 'Invalid field: amount');
            }

            // Same place, same answer, one mechanism. A malformed non-negative amount used
            // to throw out of Tiyin inside the claim — before any log write and outside the
            // controller's try — so the delivery 500d with ZERO audit rows on a delivery
            // WLCM does not retry. Asking the parser rather than writing a second rule
            // means the guard cannot drift from what the DTO will actually accept.
            try {
                Tiyin::fromSumString((string) $amount);
            } catch (InvalidAmountException) {
                abort(422, 'Invalid field: amount');
            }
        }

        $signature = $data['signature'] ?? null;

        // Some deliveries carry signature only in X-Webhook-Signature header.
        if (! is_string($signature) || $signature === '') {
            $signature = $request->header('X-Webhook-Signature');
        }

        if (! is_string($signature) || $signature === '') {
            abort(422, 'Missing field: signature');
        }

        $default = $this->config->get('wlcm.default', 'main');
        /** @var WlcmJsonScalar $default */
        $connection = $request->route('connection') ?? (string) $default;

        $requestData = $data;
        $requestData['signature'] = $signature;

        if ($isSubscription) {
            /** @var SubscriptionWebhookPayloadShape $requestData */
            $payload = SubscriptionWebhookPayload::fromArray($requestData, $connection);
        } else {
            /** @var WebhookPayloadShape $requestData */
            $payload = WebhookPayload::fromArray($requestData, $connection);
        }

        $block = ConnectionConfig::block($this->config, $connection);
        $secret = $block === null ? null : Arr::get($block, 'partner.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw MissingConfigurationException::forKey("wlcm.connections.{$connection}.partner.webhook_secret");
        }

        // The base comes off the SAME payload that gets dispatched. Deriving it from a
        // second object is what allowed the two to disagree.
        $expected = hash_hmac('sha256', $payload->signatureBase(), $secret);

        if (! hash_equals($expected, $payload->signature)) {
            abort(403, 'Invalid webhook signature');
        }

        $tolerance = $this->config->get('wlcm.webhook.tolerance_seconds', 300);

        if ($tolerance !== null) {
            if (! is_numeric($tolerance) || (int) $tolerance <= 0) {
                throw MissingConfigurationException::forKey('wlcm.webhook.tolerance_seconds', 'a positive number of seconds, or null to disable the freshness check');
            }

            $deliveredAt = self::parseTimestamp($payload->timestamp);

            if ($deliveredAt === null || abs(now()->getTimestamp() - $deliveredAt) > (int) $tolerance) {
                abort(403, 'Stale webhook timestamp');
            }
        }

        $request->attributes->set('wlcm_payload', $payload);
        // The controller must log the document we verified, not re-read the request and
        // get a different one.
        $request->attributes->set('wlcm_data', $requestData);

        return $next($request);
    }

    // Deliveries carry ms-epoch timestamps; tolerate s-epoch and datetime strings.
    private static function parseTimestamp(string $timestamp): ?int
    {
        // Self-contained, reusing the guard's own constant rather than trusting the caller
        // to have run it. strtotime() failed CLOSED on anything it could not read; the
        // DateTimeImmutable below fails OPEN — `''` and `'now'` both parse as the current
        // instant, which is a delivery that is always fresh. The caller does check the
        // shape first, but a freshness check that holds only because a neighbour catches
        // the leftovers is not a check.
        if (preg_match(self::TIMESTAMP_SHAPE, $timestamp) !== 1) {
            return null;
        }

        if (preg_match('/^\d{13}$/', $timestamp) === 1) {
            return intdiv((int) $timestamp, 1000);
        }

        if (preg_match('/^\d{10}$/', $timestamp) === 1) {
            return (int) $timestamp;
        }

        // UTC as the FALLBACK zone, not as an override. DateTimeImmutable applies the
        // given zone only when the string carries none of its own, so `Z` and `+05:00`
        // keep reading their own offset while a bare `2026-07-24 12:00:00` — what WLCM
        // actually sends — is finally read as the UTC it is.
        //
        // strtotime() resolved that bare form in the APP's timezone, so a host at +5 saw
        // every delivery five hours out and 403'd it. No tolerance_seconds a merchant
        // would set absorbs a whole UTC offset, so the delivery was simply lost. Same
        // class as the claim-window bug: two instants compared that were never on one
        // clock, fixed by naming the clock instead of inheriting it.
        try {
            return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }
}
