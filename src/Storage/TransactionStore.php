<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

/**
 * Claim-then-dispatch: claim stays unprocessed until markProcessed lands, so crash
 * mid-dispatch must re-deliver as Fresh.
 */
interface TransactionStore
{
    public function applyWebhook(WebhookPayload $payload): WebhookOutcome;

    public function markProcessed(WebhookPayload $payload): void;

    public function claimSubscription(SubscriptionWebhookPayload $payload): WebhookOutcome;

    public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload): void;

    /** @param array<string, mixed> $requestPayload */
    public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void;
}
