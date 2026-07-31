<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Storage;

final class Masker
{
    // Keys matched separator-less, lowercased: "card_number"/"cardNumber" both hit.
    private const array PAN_KEYS = ['cardnumber', 'number', 'pan', 'hpan'];

    private const array STAR_KEYS = ['otp', 'password', 'expiredate'];

    private const array DROP_KEYS = ['apisecret', 'apikey', 'secretkey', 'clientsecret', 'cardtoken', 'consumersecret', 'accesstoken', 'refreshtoken', 'authorization', 'signature'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function mask(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $normalized = null;

            if (is_string($key)) {
                $lower = strtolower($key);
                $normalized = preg_replace('/[^a-z0-9]/', '', $lower) ?? $lower;
            }

            if ($normalized !== null && in_array($normalized, self::DROP_KEYS, true)) {
                continue;
            }

            // Before recursion: PAN hidden in nested value must never be emitted.
            if ($normalized !== null && in_array($normalized, self::PAN_KEYS, true)) {
                if (! is_scalar($value)) {
                    $out[$key] = '***';

                    continue;
                }

                $digits = preg_replace('/\D/', '', (string) $value) ?? '';
                $out[$key] = strlen($digits) >= 13
                    ? substr($digits, 0, 6).str_repeat('*', strlen($digits) - 10).substr($digits, -4)
                    : '***';

                continue;
            }

            if ($normalized !== null && in_array($normalized, self::STAR_KEYS, true)) {
                $out[$key] = '***';

                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::mask($value);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrubProse(array $data): array
    {
        // Builds a FRESH array, the way mask() does, and that is load-bearing rather than
        // stylistic. Rewriting keys in place made the encoding a deletion primitive even
        // though encodeKey() is injective as a function: iteration A would unset its own
        // key and write the escaped name into a slot that iteration B then unset, so
        // `["vat%" => "GENUINE", "vat%25" => "COLLIDER"]` came out as ONE key with
        // GENUINE gone — no NUL, no attacker, ordinary merchant data. An injective
        // function applied destructively over its own input is not an injective rewrite.
        $out = [];

        foreach ($data as $key => $value) {
            $key = is_string($key) ? self::encodeKey($key) : $key;

            $out[$key] = match (true) {
                is_string($value) => self::scrubPans($value),
                is_int($value) => ($s = self::scrubPans((string) $value)) === (string) $value ? $value : $s,
                is_array($value) => self::scrubProse($value),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * Percent-escape `%` and NUL in an object key, injectively.
     *
     * A key cannot carry a raw NUL: postgres errors on any json access to the row
     * (22P05) and mysql stores it, so the two engines disagree about what a delivery
     * said. Simply stripping it collapsed distinct keys together — measured at arity 3
     * one field vanished, at arity 4 two did, and a merchant key literally named
     * `foo~1` was overwritten by the suffix scheme meant to prevent exactly that. An
     * audit trail that can be made to drop a chosen field with one captured signature
     * is a deletion primitive, not a cosmetic issue.
     *
     * Escaping the escape first makes the FUNCTION injective: distinct inputs cannot
     * share an output. That is necessary and was not sufficient — applied as an in-place
     * rewrite it still deleted fields, because an escaped name could land in a slot a
     * later iteration removed. Injectivity only survives because scrubProse() builds a
     * fresh array; the two belong together. The cost is that a key containing a literal
     * `%` is rewritten too — visible and reversible, unlike losing it.
     */
    private static function encodeKey(string $key): string
    {
        return str_replace("\0", '%00', str_replace('%', '%25', $key));
    }

    // Query parameters whose VALUE must never survive into an exception. The onboarding
    // token is the live case: it authenticates a one-shot partner creation and rides the
    // url, which ConnectionException carries into whatever error tracker the host uses.
    // Log stores were already safe — all three keep only parse_url(PHP_URL_PATH).
    private const array SECRET_QUERY_KEYS = ['token', 'api_key', 'apikey', 'api_secret', 'apisecret', 'secret', 'secret_key', 'signature', 'access_token', 'refresh_token'];

    /**
     * Blank the values of sensitive query parameters, in a url or in any text carrying
     * one. Deliberately NOT a full parse: Guzzle appends " for {uri}" to every cURL
     * error, so this has to work on a sentence as well as on a bare url. Everything else
     * is left intact — redacting the whole query would destroy the diagnostics these
     * exceptions exist to carry.
     */
    public static function redactQuery(string $text): string
    {
        $names = implode('|', array_map('preg_quote', self::SECRET_QUERY_KEYS));

        // Value runs to the next separator or whitespace; an empty value still rewrites,
        // so the output never implies a value was absent when it was merely blank.
        return preg_replace('/\b('.$names.')=[^&\s]*/i', '$1=***', $text) ?? $text;
    }

    public static function scrubPans(string $text): string
    {
        // Postgres cannot convert a json value containing an escaped NUL to text —
        // `request_body::jsonb` and `->>` both error 22P05 — and mysql stores it, so the
        // blob disagreed with its own id columns once those started stripping. Every
        // logged string reaches here, so this is where the two surfaces are made to agree.
        $text = str_replace("\0", '', $text);

        $scrubbed = self::scrubRuns($text, $text);

        // "%20" before a pan fuses into one luhn-failing run; blanking escape hex frees the pan, same width so offsets hold.
        // Both still hide a pan: escape inside it (4111%201111...) and "+" separators; view blanks escapes, never decodes.
        $view = preg_replace('/%[0-9A-Fa-f]{2}/', '%~~', $scrubbed) ?? $scrubbed;

        return $view === $scrubbed ? $scrubbed : self::scrubRuns($scrubbed, $view);
    }

    /** Runs are found in $view but masked in $text: equal width, differing only outside any run. */
    private static function scrubRuns(string $text, string $view): string
    {
        // Luhn-failing run left whole, never hunted for passing substring: false-positive control beats exhaustiveness.
        if (! preg_match_all('/(?<![0-9])(?:[0-9]{4}(?:[ -][0-9]{4}){3}|[0-9]{13,19})(?![0-9])/', $view, $matches, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $out = '';
        $cursor = 0;

        foreach ($matches[0] as [$run, $offset]) {
            $digits = preg_replace('/\D/', '', $run) ?? $run;

            if (! self::luhn($digits)) {
                continue;
            }

            $out .= substr($text, $cursor, $offset - $cursor).substr($digits, 0, 6).'***'.substr($digits, -4);
            $cursor = $offset + strlen($run);
        }

        return $out.substr($text, $cursor);
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
