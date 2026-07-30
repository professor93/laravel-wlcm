<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

final class InvalidOrderReferenceException extends WlcmException
{
    // The server enforces the same rule and answers 422. Refusing locally saves a round
    // trip on a payload the package can already see is wrong.
    public static function notExactlyOne(): self
    {
        return new self(
            'wlcm needs exactly one of orderId or externalId to generate a provider link, and was given '
            .'neither or both. Pass the one you hold: orderId when the order came back from orders()->create(), '
            .'externalId when you only kept your own reference.',
        );
    }
}
