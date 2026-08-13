<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

/**
 * HTTP said OK, the body said the operation did not happen.
 *
 * Live: /integrations/payment/confirm answers 200 {"success":false,"error":"Payment
 * not found"} for a payment it never confirmed. Returning that array reads as
 * confirmed to every caller that only checks whether a call threw, which is how a
 * merchant ships goods against an unpaid order.
 */
final class DeniedOperationException extends WlcmException {}
