<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

use Uzbek\Wlcm\Webhooks\VerifyWebhookSignature;

/**
 * Reported, never thrown: throwing would take down the host app and with it
 * route:list, the one tool that shows the merchant what happened.
 */
final class WebhookRouteConflictException extends WlcmException
{
    public static function forUri(string $uri): self
    {
        return new self(
            "Another route is serving POST /{$uri} without ".VerifyWebhookSignature::class
            .', so WLCM webhook deliveries reach it with an unverified body. Remove that route, '
            .'attach VerifyWebhookSignature to it, or move this package off the URI with wlcm.webhook.path.',
        );
    }
}
