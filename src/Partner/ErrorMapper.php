<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Partner;

use Illuminate\Http\Client\Response;
use Uzbek\Wlcm\Enums\WlcmErrorCode;
use Uzbek\Wlcm\Exceptions\AuthenticationException;
use Uzbek\Wlcm\Exceptions\AuthorizationException;
use Uzbek\Wlcm\Exceptions\ConflictException;
use Uzbek\Wlcm\Exceptions\InsufficientFundsException;
use Uzbek\Wlcm\Exceptions\InvalidOtpException;
use Uzbek\Wlcm\Exceptions\NotFoundException;
use Uzbek\Wlcm\Exceptions\ServerException;
use Uzbek\Wlcm\Exceptions\ValidationException;
use Uzbek\Wlcm\Storage\Masker;

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
                    responseBody: mb_strcut($response->body(), 0, 500, 'UTF-8'),
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

        // Derivation above reads the RAW body on purpose; only responseBody is scrubbed —
        // message/errors still carry server prose. FastAPI echoes the submitted payload there.
        $exposedBody = match (true) {
            is_array($body) => Masker::mask(self::maskDetailInputs($body)),
            // Cut on a codepoint boundary: a half character makes json_encode of the whole
            // exception context fail.
            is_string($body) => mb_strcut($body, 0, 500, 'UTF-8'),
            default => $body,
        };

        $args = [$message, $connectionName, $method, $url, $status, $exposedBody];

        // 4xx is a determinate business answer. 5xx/3xx leave the outcome unknown and 401/403 were
        // never business-evaluated, so a body code there must not be relabelled as a decline;
        // it still rides along via errorCode.
        $determinate = $status >= 400 && $status < 500;

        throw match (true) {
            $status === 401 => new AuthenticationException(...$args, errorCode: $errorCode),
            $status === 403 => new AuthorizationException(...$args, errorCode: $errorCode),
            $determinate && $errors === [] && $errorCode === WlcmErrorCode::InvalidOtp->value,
            $determinate && $errors === [] && $errorCode === WlcmErrorCode::OtpExpired->value => new InvalidOtpException(...$args, errorCode: $errorCode),
            $determinate && $errors === [] && $errorCode === WlcmErrorCode::InsufficientFunds->value => new InsufficientFundsException(...$args, errorCode: $errorCode),
            $status === 404 => new NotFoundException(...$args, errorCode: $errorCode),
            $status === 409 => new ConflictException(...$args, errorCode: $errorCode),
            $status === 422 || $status === 400 => new ValidationException(
                $message, $connectionName, $method, $url, $status, $exposedBody,
                errors: $errors, errorCode: $errorCode,
            ),
            default => new ServerException(...$args, errorCode: $errorCode),
        };
    }

    // Pydantic sends `input` as the bare failing value for field-level errors, invisible to a
    // key-based masker. Re-key under the loc tail so Masker's own rules decide.
    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public static function maskDetailInputs(array $body): array
    {
        $detail = $body['detail'] ?? null;

        if (! is_array($detail)) {
            return $body;
        }

        foreach ($detail as $i => $item) {
            if (! is_array($item) || ! array_key_exists('input', $item) || is_array($item['input'])) {
                continue;
            }

            $locRaw = $item['loc'] ?? null;
            $loc = is_array($locRaw) ? $locRaw : [];
            $last = end($loc);
            $field = is_scalar($last) ? (string) $last : '';

            if ($field === '') {
                continue;
            }

            // A DROP key masks to nothing at all, so an absent key means "withhold".
            $masked = Masker::mask([$field => $item['input']]);
            $item['input'] = array_key_exists($field, $masked) ? $masked[$field] : '***';
            $detail[$i] = $item;
        }

        $body['detail'] = $detail;

        return $body;
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
