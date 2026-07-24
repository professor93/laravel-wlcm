<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;

final class SubscriptionCancelled
{
    use Dispatchable;

    public function __construct(public readonly SubscriptionWebhookPayload $payload) {}
}
