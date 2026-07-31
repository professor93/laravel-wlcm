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

// Maps Partner API responses (FastAPI error shapes) onto package exception tree.
final class ErrorMapper
{
    /** @return array<string, mixed> */
    public static function handle(Response $response, string $connectionName, string $method, string $url): array
    {
        // Query carries merchant free-text (fiscalization external_id, subscription lookup),
        // and exception context is what the merchant's default handler writes to their log.
        // redactQuery on top: the onboarding token authenticates a one-shot partner
        // creation and rides ?token=, and a 400 invalid_or_expired is the ORDINARY
        // failure there — far more likely to be seen than a transport error.
        $url = Masker::redactQuery(Masker::scrubPans($url));

        if ($response->successful()) {
            $json = $response->json();

            if (! is_array($json)) {
                // Garbage 200 body (proxy page, truncated JSON) must not read as unpaid.
                throw new ServerException(
                    "WLCM returned HTTP {$response->status()} with a non-JSON body",
                    connectionName: $connectionName,
                    requestMethod: $method,
                    requestPath: $url,
                    responseStatus: $response->status(),
                    // Scrub before cut: truncated PAN check digit Luhn-recoverable.
                    // Pre-cut bounds scan on huge bodies, never reaching kept bytes:
                    // scrub collapses 20 chars to 14 at worst, so the 500 that survive
                    // come from no further in than byte 734.
                    responseBody: mb_strcut(Masker::scrubPans(mb_strcut($response->body(), 0, 1024, 'UTF-8')), 0, 500, 'UTF-8'),
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
            $detailCode = null;

            // A LIST detail is pydantic's validation array; a DICT one is this API's own
            // {message, code, suggestions} envelope. Reading the dict as a list threw both away.
            if (is_array($detail) && array_is_list($detail)) {
                [$detailMessage, $errors] = self::parseFastApiDetail($detail);
                $message = $detailMessage ?? $message;
            } elseif (is_array($detail)) {
                $detailMessage = $detail['message'] ?? null;
                // Cast first, then test: `false` casts to "" and an empty message is worse
                // than the reason phrase. "0" is a real message and survives.
                $candidate = is_scalar($detailMessage) ? (string) $detailMessage : '';
                $message = $candidate !== '' ? $candidate : $message;
                $detailCode = $detail['code'] ?? null;
            } elseif ($detail !== null) {
                /** @var WlcmJsonScalar $detail */
                $message = (string) $detail;
            }

            $code = $body['code'] ?? null;
            // Machine codes live in `code`, inside a dict detail, or arrive as bare snake_case
            // detail string. Live codes are UPPER_CASE and have no enum case: pass them raw.
            $errorCode = match (true) {
                is_string($code) && $code !== '' => $code,
                // Case-insensitive: live codes are UPPER_CASE. Guarded so server prose
                // cannot masquerade as a code and ride out unscrubbed.
                //
                // \z not $, the choice Tiyin::fromSumString documents: PCRE $ matches
                // before a final newline, so "invalid_otp\n" slipped both guards. That is
                // not cosmetic — errorCode() then returns a value a merchant's
                // === 'invalid_otp' misses, and errorCodeEnum() cannot resolve a code the
                // package knows, dropping their match into its default arm. A trailing
                // newline is exactly the prose these guards exist to refuse.
                is_string($detailCode) && preg_match('/^[A-Za-z0-9_]+\z/', $detailCode) === 1 => $detailCode,
                is_string($detail) && preg_match('/^[a-z0-9_]+\z/', $detail) === 1 => $detail,
                default => null,
            };

            // errorCode is the only string reaching the exception without a scrub of its
            // own. No-op on a real code; kills an all-digit one that slips the guard.
            $errorCode = $errorCode === null ? null : Masker::scrubPans($errorCode);

            $errorsRaw = $body['errors'] ?? null;

            if (is_array($errorsRaw)) {
                /** @var array<string, list<string>> $errorsRaw */
                $errors = $errorsRaw;
            }
        }

        $message = Masker::scrubPans($message);
        /** @var array<string, list<string>> $errors */
        $errors = Masker::scrubProse($errors);

        // Derivation above reads RAW body on purpose. Every string/int surface leaving on the
        // exception is PAN-scrubbed: message/errors keep server prose minus PANs; responseBody
        // additionally key-masked — FastAPI echoes submitted payload there.
        $exposedBody = match (true) {
            is_array($body) => Masker::scrubProse(Masker::mask(self::maskDetailInputs($body))),
            // Cut on codepoint boundary: half character fails json_encode of whole exception context.
            is_string($body) => mb_strcut(Masker::scrubPans(mb_strcut($body, 0, 1024, 'UTF-8')), 0, 500, 'UTF-8'),
            is_int($body) => ($s = Masker::scrubPans((string) $body)) === (string) $body ? $body : $s,
            default => $body,
        };

        $args = [$message, $connectionName, $method, $url, $status, $exposedBody];

        // 4xx = determinate business answer. 5xx/3xx leave outcome unknown, 401/403 never
        // business-evaluated: body code there must not be relabelled a decline. Still rides errorCode.
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

    // Pydantic sends `input` as bare failing value for field-level errors, invisible to
    // key-based masker. Re-key under loc tail so Masker's own rules decide.
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

            // DROP key masks to nothing at all, so absent key means "withhold".
            $masked = Masker::mask([$field => $item['input']]);
            $item['input'] = array_key_exists($field, $masked) ? $masked[$field] : '***';
            $detail[$i] = $item;
        }

        $body['detail'] = $detail;

        return $body;
    }

    /**
     * FastAPI 422 detail: list of {type, loc, msg, input, ctx}. Message keeps full loc
     * path ("body.return_url: Field required"); errors() keys drop leading "body".
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
