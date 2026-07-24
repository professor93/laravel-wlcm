<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner;

use Illuminate\Http\Client\Response;
use Uzbek\Wlcm\Exceptions\AuthenticationException;
use Uzbek\Wlcm\Exceptions\AuthorizationException;
use Uzbek\Wlcm\Exceptions\ConflictException;
use Uzbek\Wlcm\Exceptions\NotFoundException;
use Uzbek\Wlcm\Exceptions\ServerException;
use Uzbek\Wlcm\Exceptions\ValidationException;

// Maps Partner API responses (FastAPI error shapes) onto the package exception tree.
final class ErrorMapper
{
    /** @return array<string, mixed> */
    public static function handle(Response $response, string $connectionName, string $method, string $url): array
    {
        if ($response->successful()) {
            $json = $response->json();

            if (! is_array($json)) {
                // 200 with garbage body (proxy page, truncated JSON) must never read as "not paid".
                throw new ServerException(
                    "WLCM returned HTTP {$response->status()} with a non-JSON body",
                    connectionName: $connectionName,
                    requestMethod: $method,
                    requestPath: $url,
                    responseStatus: $response->status(),
                    responseBody: substr($response->body(), 0, 500),
                );
            }

            /** @var array<string, mixed> $json */
            return $json;
        }

        $status = $response->status();
        $body = $response->json() ?? $response->body();
        $message = $response->reason();
        $errors = [];
        $errorCode = null;

        if (is_array($body)) {
            /** @var array<string, mixed> $body */
            $detail = $body['detail'] ?? $body['message'] ?? null;

            if (is_array($detail)) {
                [$detailMessage, $errors] = self::parseFastApiDetail($detail);
                $message = $detailMessage ?? $message;
            } elseif ($detail !== null) {
                /** @var WlcmJsonScalar $detail */
                $message = (string) $detail;
            }

            $code = $body['code'] ?? null;
            // Machine codes live in `code`, or arrive as a bare snake_case detail string.
            $errorCode = match (true) {
                is_string($code) && $code !== '' => $code,
                is_string($detail) && preg_match('/^[a-z0-9_]+$/', $detail) === 1 => $detail,
                default => null,
            };

            $errorsRaw = $body['errors'] ?? null;

            if (is_array($errorsRaw)) {
                /** @var array<string, list<string>> $errorsRaw */
                $errors = $errorsRaw;
            }
        }

        $args = [$message, $connectionName, $method, $url, $status, $body];

        throw match (true) {
            $status === 401 => new AuthenticationException(...$args, errorCode: $errorCode),
            $status === 403 => new AuthorizationException(...$args, errorCode: $errorCode),
            $status === 404 => new NotFoundException(...$args, errorCode: $errorCode),
            $status === 409 => new ConflictException(...$args, errorCode: $errorCode),
            $status === 422 || $status === 400 => new ValidationException(
                $message, $connectionName, $method, $url, $status, $body,
                errors: $errors, errorCode: $errorCode,
            ),
            default => new ServerException(...$args, errorCode: $errorCode),
        };
    }

    /**
     * FastAPI 422 detail: list of {type, loc, msg, input, ctx}. Message keeps the full
     * loc path ("body.return_url: Field required"); errors() keys drop the leading "body".
     *
     * @param  array<mixed>  $detail
     * @return array{0: string|null, 1: array<string, list<string>>}
     */
    private static function parseFastApiDetail(array $detail): array
    {
        $message = null;
        $errors = [];

        foreach ($detail as $item) {
            if (! is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $locRaw = $item['loc'] ?? null;
            $loc = [];

            if (is_array($locRaw)) {
                foreach ($locRaw as $part) {
                    if (is_scalar($part)) {
                        $loc[] = (string) $part;
                    }
                }
            }

            $msgRaw = $item['msg'] ?? null;
            $msg = is_scalar($msgRaw) ? (string) $msgRaw : '';
            $path = implode('.', $loc);
            $message ??= $path === '' ? ($msg === '' ? null : $msg) : "{$path}: {$msg}";

            if ($loc !== [] && $loc[0] === 'body') {
                array_shift($loc);
            }

            $field = implode('.', $loc);

            if ($field !== '' && $msg !== '') {
                $errors[$field][] = $msg;
            }
        }

        return [$message, $errors];
    }
}
