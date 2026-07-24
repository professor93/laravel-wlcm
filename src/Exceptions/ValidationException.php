<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Exceptions;

use Throwable;

final class ValidationException extends WlcmException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message,
        ?string $connectionName = null,
        ?string $requestMethod = null,
        ?string $requestPath = null,
        ?int $responseStatus = null,
        mixed $responseBody = null,
        ?Throwable $previous = null,
        public readonly array $errors = [],
        ?string $errorCode = null,
    ) {
        parent::__construct($message, $connectionName, $requestMethod, $requestPath, $responseStatus, $responseBody, $previous, $errorCode);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
