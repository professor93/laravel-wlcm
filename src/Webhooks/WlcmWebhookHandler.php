<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

interface WlcmWebhookHandler
{
    public function handle(WebhookPayload $payload): void;
}
