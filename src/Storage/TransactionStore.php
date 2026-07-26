<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

/**
 * Claim-then-dispatch: claim stays unprocessed until markProcessed lands, so crash
 * mid-dispatch must re-deliver as Fresh.
 *
 * The token is the caller's dispatch identity and must come back on mark: a newer
 * delivery may legitimately own the row by then, and stamping ITS claim processed
 * swallows that delivery's redelivery for good.
 */
interface TransactionStore
{
    public function applyWebhook(WebhookPayload $payload, string $token): WebhookOutcome;

    public function markProcessed(WebhookPayload $payload, string $token): void;

    public function claimSubscription(SubscriptionWebhookPayload $payload, string $token): WebhookOutcome;

    public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload, string $token): void;

    /** @param array<string, mixed> $requestPayload */
    public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void;
}
