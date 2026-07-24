<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;

final readonly class VerifyWebhookSignature
{
    private const array REQUIRED_PAYMENT = ['order_id', 'payment_id', 'state', 'timestamp'];

    private const array REQUIRED_SUBSCRIPTION = ['external_order_id', 'event', 'timestamp'];

    public function __construct(private Repository $config) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Subscription events carry an `event` field; payment webhooks never do.
        $isSubscription = $request->input('event') !== null;

        foreach ($isSubscription ? self::REQUIRED_SUBSCRIPTION : self::REQUIRED_PAYMENT as $field) {
            $value = $request->input($field);

            if ($value === null || ! is_scalar($value)) {
                abort(422, "Missing field: {$field}");
            }
        }

        $signature = $request->input('signature');

        // Some deliveries carry the signature only in the X-Webhook-Signature header.
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

        $secret = $this->config->get("wlcm.connections.{$connection}.partner.webhook_secret");

        if (! is_string($secret) || $secret === '') {
            throw MissingConfigurationException::forKey("wlcm.connections.{$connection}.partner.webhook_secret");
        }

        $expected = hash_hmac('sha256', $payload->signatureBase(), $secret);

        if (! hash_equals($expected, $payload->signature)) {
            abort(403, 'Invalid webhook signature');
        }

        $request->attributes->set('wlcm_payload', $payload);

        return $next($request);
    }
}
