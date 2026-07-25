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
        $isSubscription = $request->input('event') !== null;

        foreach ($isSubscription ? self::REQUIRED_SUBSCRIPTION : self::REQUIRED_PAYMENT as $field) {
            $value = $request->input($field);

            if ($value === null || ! is_scalar($value)) {
                abort(422, "Missing field: {$field}");
            }
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
