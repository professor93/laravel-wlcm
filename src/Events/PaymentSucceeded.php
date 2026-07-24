<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Uzbek\Wlcm\Webhooks\WebhookPayload;

final class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public readonly WebhookPayload $payload) {}
}
