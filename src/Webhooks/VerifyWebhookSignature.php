<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Support\ConnectionConfig;

/**
 * @phpstan-import-type WebhookPayloadShape from WebhookPayload
 * @phpstan-import-type SubscriptionWebhookPayloadShape from SubscriptionWebhookPayload
 */
final readonly class VerifyWebhookSignature
{
    private const array REQUIRED_PAYMENT = ['order_id', 'payment_id', 'amount', 'state', 'timestamp'];

    private const array REQUIRED_SUBSCRIPTION = ['external_order_id', 'event', 'timestamp'];

    public function __construct(private Repository $config) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
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

        // Subscription events carry `event` field; payment webhooks never do. Empty counts
        // as absent, matching what a stock app's ConvertEmptyStringsToNull already did.
        $event = $data['event'] ?? null;
        $isSubscription = $event !== null && $event !== '';

        // A payment base {order_id}:{payment_id}:{state}:{timestamp} and a subscription
        // base {external_order_id}:{event}:{timestamp} are both bare colon joins, so a
        // captured payment delivery can replay as a subscription with its signature
        // UNCHANGED.
        //
        // Completeness: for a collision `X:E` must equal `O:P:S`, so X is a prefix of
        // O:P:S ending on a colon boundary. Either X carries a colon, or X is exactly O,
        // in which case E is `P:S` and carries one. Refusing a colon in EITHER field
        // closes every split — and unlike a rule about the event alone it holds whatever
        // the timestamp looks like. `2026-07-24 12:00:00` has colons of its own, which let
        // event = "2026-07-24 12" collide while being neither numeric nor colon-bearing;
        // that case is exactly why an event-only rule was not exhaustive.
        //
        // Not keyed on `subscription.` naming: observed is not exhaustive, and refusing an
        // unfamiliar-but-legitimate event kind would lose a real payment event. The cost
        // is that a merchant reference may not contain a colon — there is no escape for
        // that field, so it is documented rather than worked around.
        //
        // Scalar-gated: a non-scalar keeps its "Missing field" answer from the loop below.
        foreach (['event', 'external_order_id'] as $field) {
            $value = $data[$field] ?? null;

            if ($isSubscription && is_scalar($value) && str_contains((string) $value, ':')) {
                abort(422, "Invalid field: {$field}");
            }
        }

        foreach ($isSubscription ? self::REQUIRED_SUBSCRIPTION : self::REQUIRED_PAYMENT as $field) {
            $value = $data[$field] ?? null;

            // Empty counts as missing. An empty payment_id coerces to '' and every such
            // row collides on unique(connection, ''), where a claim would answer the
            // second, genuinely distinct delivery Duplicate and swallow it.
            if ($value === null || ! is_scalar($value) || (string) $value === '') {
                abort(422, "Missing field: {$field}");
            }
        }

        // `amount` rides outside the signature base by WLCM's design, so one captured
        // delivery lets anyone set it freely. A negative reaches an unsignedBigInteger
        // column that mysql rejects with a 500 and postgres accepts — storing negative
        // money and firing the events on the same input mysql refuses. Refused here, on
        // both engines, before any claim can exist. Refusing beats clamping: a silently
        // corrected money value is worse than a delivery someone can be asked to resend.
        $amount = $data['amount'] ?? null;

        if (! $isSubscription && is_scalar($amount) && str_starts_with(ltrim((string) $amount), '-')) {
            abort(422, 'Invalid field: amount');
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
                throw MissingConfigurationException::forKey('wlcm.webhook.tolerance_seconds');
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
        if (preg_match('/^\d{13}$/', $timestamp) === 1) {
            return intdiv((int) $timestamp, 1000);
        }

        if (preg_match('/^\d{10}$/', $timestamp) === 1) {
            return (int) $timestamp;
        }

        $parsed = strtotime($timestamp);

        return $parsed === false ? null : $parsed;
    }
}
