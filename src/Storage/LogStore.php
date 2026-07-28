<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

/**
 * Producers mask before calling: implementations receive pre-masked data, persist verbatim.
 * Never feed raw request/response bodies here.
 */
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

    /**
     * $payload is the scrubbed blob to persist. $ids carries the correlation columns
     * derived from the RAW delivery — server ids byte-intact, reference scrubbed —
     * because the blob's whole-body scrub mangles a luhn-coincident server id.
     * Omit $ids and they fall back to the blob, which is the old lossy behaviour.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|null>  $ids
     */
    public function webhook(string $connection, array $payload, string $result, array $ids = []): void;
}
