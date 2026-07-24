<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Uzbek\Wlcm\Events\PaymentCancelled;
use Uzbek\Wlcm\Events\PaymentSucceeded;
use Uzbek\Wlcm\Events\SubscriptionActivated;
use Uzbek\Wlcm\Events\SubscriptionCancelled;
use Uzbek\Wlcm\Events\SubscriptionCharged;
use Uzbek\Wlcm\Events\SubscriptionChargeFailed;
use Uzbek\Wlcm\Events\WebhookReceived;
use Uzbek\Wlcm\Storage\LogWriter;
use Uzbek\Wlcm\Storage\TransactionRecorder;

final readonly class WebhookController
{
    public function __construct(
        private Repository $config,
        private Container $container,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var WebhookPayload|SubscriptionWebhookPayload $payload */
        $payload = $request->attributes->get('wlcm_payload');

        event(new WebhookReceived($payload));

        $requestData = (array) $request->all();
        /** @var array<string, mixed> $requestData */
        if ($payload instanceof SubscriptionWebhookPayload) {
            return $this->handleSubscription($payload, $requestData);
        }

        if (! TransactionRecorder::applyWebhook($payload)) {
            LogWriter::webhook((string) $payload->connection, $requestData, 'duplicate');

            return new JsonResponse(['status' => 'ok']);
        }

        LogWriter::webhook((string) $payload->connection, $requestData, 'ok');

        if ($payload->isSuccess()) {
            event(new PaymentSucceeded($payload));
        } elseif ($payload->isCancelled()) {
            event(new PaymentCancelled($payload));
        }

        $handler = $this->config->get('wlcm.webhook.handler');

        if (is_string($handler) && $handler !== '') {
            $instance = $this->container->make($handler);

            if ($instance instanceof WlcmWebhookHandler) {
                $instance->handle($payload);
            }
        }

        return new JsonResponse(['status' => 'ok']);
    }

    /** @param array<string, mixed> $requestData */
    private function handleSubscription(SubscriptionWebhookPayload $payload, array $requestData): JsonResponse
    {
        LogWriter::webhook((string) $payload->connection, $requestData, 'ok');

        match ($payload->event) {
            'subscription.activated' => event(new SubscriptionActivated($payload)),
            'subscription.charged' => event(new SubscriptionCharged($payload)),
            'subscription.charge_failed' => event(new SubscriptionChargeFailed($payload)),
            'subscription.cancelled' => event(new SubscriptionCancelled($payload)),
            default => null,
        };

        $handler = $this->config->get('wlcm.webhook.subscription_handler');

        if (is_string($handler) && $handler !== '') {
            $instance = $this->container->make($handler);

            if ($instance instanceof WlcmSubscriptionWebhookHandler) {
                $instance->handle($payload);
            }
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
