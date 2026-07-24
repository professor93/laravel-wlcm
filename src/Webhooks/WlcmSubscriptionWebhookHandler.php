<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

interface WlcmSubscriptionWebhookHandler
{
    public function handle(SubscriptionWebhookPayload $payload): void;
}
