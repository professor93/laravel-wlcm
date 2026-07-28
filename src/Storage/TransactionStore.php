<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

use Uzbek\Wlcm\Partner\Data\CheckoutResponse;
use Uzbek\Wlcm\Partner\Data\Order;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

/**
 * Claim-then-dispatch. The claim is a conditional UPDATE (or an INSERT arbitrated by a
 * unique index): its affected-row count IS the verdict, so exactly one of N concurrent
 * callers gets Fresh. An implementation that decides by READING processed_at re-creates
 * the defect this interface exists to prevent.
 *
 * The token is the caller's dispatch identity and must come back on mark/release: a
 * newer delivery may legitimately own the row by then. processed_at is still stamped
 * only after the handler returns.
 */
interface TransactionStore
{
    public function applyWebhook(WebhookPayload $payload, string $token): WebhookOutcome;

    public function markProcessed(WebhookPayload $payload, string $token): void;

    public function releaseClaim(WebhookPayload $payload, string $token): void;

    public function claimSubscription(SubscriptionWebhookPayload $payload, string $token): WebhookOutcome;

    public function markSubscriptionProcessed(SubscriptionWebhookPayload $payload, string $token): void;

    public function releaseSubscriptionClaim(SubscriptionWebhookPayload $payload, string $token): void;

    /** @param array<string, mixed> $requestPayload */
    public function recordCheckout(string $connection, array $requestPayload, CheckoutResponse $response): void;

    /** @param array<string, mixed> $requestPayload */
    public function recordOrder(string $connection, array $requestPayload, Order $order): void;
}
