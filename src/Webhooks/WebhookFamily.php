<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

/**
 * The one place that decides which family a delivery belongs to.
 *
 * There used to be two: the middleware asked `!== null && !== ''`, WebhookLogIds asked
 * `isset()`. `isset('')` is true, so a payment carrying `"event": ""` was routed as a
 * payment by one and a subscription by the other — and since `event` sits outside the
 * payment signature base, one captured signature was enough to null the audit row's
 * correlation column. Two predicates for one question is the defect; a second copy that
 * merely agrees today would be the same defect waiting.
 */
final class WebhookFamily
{
    /** @param array<string, mixed> $payload */
    public static function isSubscription(array $payload): bool
    {
        $event = $payload['event'] ?? null;

        // Empty counts as absent, which is what a stock app's ConvertEmptyStringsToNull
        // produced before the raw-body read stopped it converting anything.
        return $event !== null && $event !== '';
    }
}
