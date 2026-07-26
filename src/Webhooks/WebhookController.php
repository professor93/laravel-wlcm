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
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Storage\Masker;
use Uzbek\Wlcm\Storage\TransactionStore;
use Uzbek\Wlcm\Storage\WebhookLogIds;
use Uzbek\Wlcm\Storage\WebhookOutcome;

final readonly class WebhookController
{
    public function __construct(
        private Repository $config,
        private Container $container,
        private LogStore $logStore,
        private TransactionStore $transactions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var WebhookPayload|SubscriptionWebhookPayload $payload */
        $payload = $request->attributes->get('wlcm_payload');

        /** @var array<string, mixed> $raw */
        $raw = (array) $request->all();
        /** @var array<string, mixed> $requestData */
        $requestData = Masker::scrubProse(Masker::mask($raw));
        // Blob keeps the whole-body scrub; correlation columns come off the raw delivery,
        // because that scrub would mangle a luhn-coincident order_id or payment_id.
        $logIds = WebhookLogIds::from($raw);

        $token = $this->claimToken();

        if ($payload instanceof SubscriptionWebhookPayload) {
            return $this->handleSubscription($payload, $requestData, $logIds, $token);
        }

        $outcome = $this->transactions->applyWebhook($payload, $token);

        if ($outcome !== WebhookOutcome::Fresh) {
            $this->logStore->webhook((string) $payload->connection, $requestData, $outcome === WebhookOutcome::Stale ? 'stale' : 'duplicate', $logIds);

            return new JsonResponse(['status' => 'ok']);
        }

        $this->logStore->webhook((string) $payload->connection, $requestData, 'ok', $logIds);

        event(new WebhookReceived($payload));

        if ($payload->isSuccess()) {
            event(new PaymentSucceeded($payload));
        } elseif ($payload->isCancelled()) {
            event(new PaymentCancelled($payload));
        }

        $handler = $this->config->get('wlcm.webhook.handler');

        if (is_string($handler) && $handler !== '') {
            $instance = $this->container->make($handler);

            if (! $instance instanceof WlcmWebhookHandler) {
                throw MissingConfigurationException::forKey('wlcm.webhook.handler');
            }

            $instance->handle($payload);
        }

        $this->transactions->markProcessed($payload, $token);

        return new JsonResponse(['status' => 'ok']);
    }

    // Per-attempt claim identity. Minted here because the controller is what must hand
    // it back on mark.
    private function claimToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, string|null>  $logIds
     */
    private function handleSubscription(SubscriptionWebhookPayload $payload, array $requestData, array $logIds, string $token): JsonResponse
    {
        $outcome = $this->transactions->claimSubscription($payload, $token);

        if ($outcome !== WebhookOutcome::Fresh) {
            $this->logStore->webhook((string) $payload->connection, $requestData, 'duplicate', $logIds);

            return new JsonResponse(['status' => 'ok']);
        }

        $this->logStore->webhook((string) $payload->connection, $requestData, 'ok', $logIds);

        event(new WebhookReceived($payload));

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

            if (! $instance instanceof WlcmSubscriptionWebhookHandler) {
                throw MissingConfigurationException::forKey('wlcm.webhook.subscription_handler');
            }

            $instance->handle($payload);
        }

        $this->transactions->markSubscriptionProcessed($payload, $token);

        return new JsonResponse(['status' => 'ok']);
    }
}
