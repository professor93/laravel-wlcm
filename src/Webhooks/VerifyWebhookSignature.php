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
        // Subscription events carry `event` field; payment webhooks never do.
        $event = $request->input('event');
        $isSubscription = $event !== null;

        // Neither signature base carries a domain tag, and both are bare colon joins, so
        // a payment base {order_id}:{payment_id}:{state}:{timestamp} is byte-identical to
        // a subscription base {external_order_id}:{event}:{timestamp} when the reference
        // holds the colon — a captured payment delivery replays as a subscription with
        // its signature UNCHANGED. Every real event is a `subscription.` kind, so
        // refusing anything else closes that direction without a wire-format change and
        // without constraining merchant free text. The reverse direction needs a domain
        // tag in both bases, which is WLCM's to change, not ours.
        // Scalar only: a non-scalar event is unusable for a different reason and keeps its
        // existing "Missing field" answer from the loop below. Casting catches a bare
        // integer event, which is exactly the shape the collision needs.
        if ($isSubscription && is_scalar($event) && ! str_starts_with((string) $event, 'subscription.')) {
            abort(422, 'Invalid field: event');
        }

        foreach ($isSubscription ? self::REQUIRED_SUBSCRIPTION : self::REQUIRED_PAYMENT as $field) {
            $value = $request->input($field);

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
        $amount = $request->input('amount');

        if (! $isSubscription && is_scalar($amount) && str_starts_with(ltrim((string) $amount), '-')) {
            abort(422, 'Invalid field: amount');
        }

        $signature = $request->input('signature');

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

        $requestData = (array) $request->all();
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

        // Verify over the WIRE values, not the framework's. A stock app runs TrimStrings and
        // ConvertEmptyStringsToNull in the global stack before this middleware, so
        // $request->all() has already been rewritten — and an hmac checked against
        // rewritten input refuses a delivery WLCM signed correctly, which no merchant can
        // fix without disabling TrimStrings application-wide. Only the base is rebuilt;
        // family detection, the 422 checks and the payload handed downstream are
        // unchanged. A body that is not a json object falls back to the parsed input,
        // which is the pre-existing behaviour rather than a new refusal.
        $decoded = json_decode($request->getContent(), true);
        $signedData = is_array($decoded) ? $decoded : $requestData;
        $signedData['signature'] = $signature;

        /** @var WebhookPayloadShape&SubscriptionWebhookPayloadShape $signedData */
        $signed = $isSubscription
            ? SubscriptionWebhookPayload::fromArray($signedData, $connection)
            : WebhookPayload::fromArray($signedData, $connection);

        $expected = hash_hmac('sha256', $signed->signatureBase(), $secret);

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
