<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

/** Masking stays in front of every implementation: no PAN, OTP or secret reaches a store. */
interface LogStore
{
    /**
     * @param  array<string, mixed>|null  $requestBody
     * @param  array<string, string|null>  $ids
     */
    public function out(
        string $connection,
        string $api,
        string $method,
        string $url,
        ?array $requestBody,
        mixed $responseBody,
        ?int $status,
        int $durationMs,
        array $ids = [],
        string $result = 'ok',
    ): void;

    /** @param array<string, mixed> $payload */
    public function webhook(string $connection, array $payload, string $result): void;
}
