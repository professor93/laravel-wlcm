<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Webhooks;

use Throwable;

/**
 * One definition of "this delivery needs attention", shared by `wlcm:webhook:events
 * --failed` and by the scaffolded listeners, so the CLI and your code cannot disagree.
 *
 * WHY IT IS NOT JUST `status !== 'delivered'`. Across 59 real deliveries on the sandbox,
 * every single record read `failed` — no successful delivery has ever been observed, so
 * the success string is a GUESS. A predicate resting on it alone would silently pass
 * every delivery the day WLCM's vocabulary turned out to be `success` or `ok`.
 *
 * So several independent signals are combined, and the load-bearing one is
 * `error_message`: a delivery carrying an error text has failed whatever its status is
 * called. Every rule is configurable under `wlcm.webhook.alerts`; a rule set to null is
 * switched off.
 */
final class DeliveryHealth
{
    /** @param array<string, mixed> $delivery */
    public static function problematic(array $delivery): bool
    {
        // An empty or unrecognisable record is not evidence of health. Reporting it as
        // fine is the failure this predicate exists to prevent.
        if ($delivery === []) {
            return true;
        }

        foreach (self::rules() as $rule) {
            if ($rule($delivery)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<callable(array<string, mixed>): bool> */
    private static function rules(): array
    {
        return [
            static function (array $d): bool {
                $healthy = config('wlcm.webhook.alerts.status_not_in', ['delivered']);

                if (! is_array($healthy) || $healthy === []) {
                    return false;
                }

                $status = $d['status'] ?? null;

                return is_scalar($status) && ! in_array((string) $status, array_map(static fn (mixed $s): string => is_scalar($s) ? (string) $s : '', $healthy), true);
            },

            // The one that survives a wrong guess about the success vocabulary.
            static function (array $d): bool {
                if (config('wlcm.webhook.alerts.has_error_message', true) !== true) {
                    return false;
                }

                $error = $d['error_message'] ?? null;

                return is_scalar($error) && (string) $error !== '';
            },

            static function (array $d): bool {
                $ok = config('wlcm.webhook.alerts.response_code_not_in', [200, 201, 202, 204]);

                if (! is_array($ok) || $ok === []) {
                    return false;
                }

                $code = $d['response_code'] ?? null;

                // Null means no HTTP exchange happened. That is only a problem when
                // nothing else already said so — a queued delivery has no code yet.
                return is_numeric($code) && ! in_array((int) $code, array_map(static fn (mixed $c): int => is_numeric($c) ? (int) $c : -1, $ok), true);
            },

            static function (array $d): bool {
                $threshold = config('wlcm.webhook.alerts.attempts_gte', 2);

                if (! is_numeric($threshold)) {
                    return false;
                }

                $attempts = $d['attempts'] ?? null;

                return is_numeric($attempts) && (int) $attempts >= (int) $threshold;
            },

            // A retry that was due and did not happen is a stuck delivery.
            static function (array $d): bool {
                if (config('wlcm.webhook.alerts.stale_retry', true) !== true) {
                    return false;
                }

                $retry = $d['next_retry_at'] ?? null;

                if (! is_scalar($retry) || (string) $retry === '') {
                    return false;
                }

                try {
                    return strtotime((string) $retry) < time();
                } catch (Throwable) {
                    // An unparseable timestamp is not evidence of health either.
                    return true;
                }
            },
        ];
    }
}
