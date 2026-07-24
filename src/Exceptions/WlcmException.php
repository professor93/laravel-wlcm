<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

use RuntimeException;
use Throwable;

class WlcmException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $connectionName = null,
        public readonly ?string $requestMethod = null,
        public readonly ?string $requestPath = null,
        public readonly ?int $responseStatus = null,
        public readonly mixed $responseBody = null,
        ?Throwable $previous = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}
