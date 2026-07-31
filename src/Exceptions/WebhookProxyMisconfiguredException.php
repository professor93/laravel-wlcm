<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

/**
 * Reported, never thrown. The delivery is already refused by the time this is built —
 * this exists so the refusal has a reason attached in the operator's error tracker,
 * rather than being an unexplained 403.
 */
final class WebhookProxyMisconfiguredException extends WlcmException {}
