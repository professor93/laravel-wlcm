<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

final class CallerTransactionException extends WlcmException
{
    // Key, accepted values and remedy in the message itself. Whoever reads this is
    // mid-payment and must not have to open the source to get out of it.
    private const string REMEDY = 'Move the call outside your DB::transaction(), or set wlcm.allow_caller_transaction to true (accepted: true/false) to accept the risk.';

    public static function webhookClaim(): self
    {
        return new self(
            'wlcm refused a webhook delivery inside a caller-opened database transaction. '
            .'The dedupe claim rolls back with your transaction, but the events it guards fire in process and cannot — '
            .'so a rollback leaves the money event fired with no record of it, and the next redelivery fires it again. '
            .self::REMEDY,
        );
    }

    public static function checkout(): self
    {
        return new self(
            'wlcm refused a checkout inside a caller-opened database transaction. Nothing was sent to WLCM. '
            .'The wlcm_transactions row linking the payment to your order would roll back with your transaction, '
            .'leaving WLCM holding a real payment this package cannot correlate to anything. '
            .self::REMEDY,
        );
    }
}
