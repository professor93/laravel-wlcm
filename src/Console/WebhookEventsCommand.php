<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console;

use Illuminate\Console\Command;
use Throwable;
use Uzbek\Wlcm\Console\Concerns\ResolvesWebhook;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Webhooks\DeliveryHealth;

/**
 * WLCM's delivery log, which answers a different question from `wlcm_logs`.
 *
 * The local audit trail records deliveries that ARRIVED. This records what WLCM tried to
 * send — including everything that never reached you at all, which is precisely the case
 * you cannot diagnose from your own logs.
 */
final class WebhookEventsCommand extends Command
{
    use ResolvesWebhook;

    /** Usage error: the operator got it wrong, as opposed to the server saying no. */
    private const int USAGE = 2;

    protected $signature = 'wlcm:webhook:events
        {id? : Webhook id; prompts from the live list when omitted}
        {--failed : Only deliveries that need attention}
        {--status= : Exact status match}
        {--event= : Exact event_type match}
        {--since= : Relative like 2h or 3d, or any parseable date}
        {--limit=20 : Rows to show; 0 for all}
        {--connection= : Target connection (default: wlcm.default)}
        {--json : Raw server objects instead of a table}';

    protected $description = 'Show WLCM\'s delivery history for a webhook';

    public function handle(): int
    {
        $argument = $this->argument('id');
        $id = $this->resolveWebhookId(is_numeric($argument) ? (int) $argument : null);

        if ($id === null) {
            return self::USAGE;
        }

        $since = $this->resolveSince();

        if ($since === false) {
            $this->components->error('Could not read --since. Use 2h, 3d, or a date.');

            return self::USAGE;
        }

        try {
            $events = Wlcm::connection($this->connectionName())->partner()->webhooks()->events($id);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $events = $this->filter($events, $since);

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $shown = array_slice($events, 0, $limit);

            // Never truncate in silence: a partial view that looks complete is how an
            // operator concludes a delivery never happened.
            if (count($events) > $limit) {
                $this->components->warn(sprintf('Showing %d of %d. Pass --limit=0 for all.', $limit, count($events)));
            }

            $events = $shown;
        }

        $this->render($events);

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function filter(array $events, ?int $since): array
    {
        $status = $this->option('status');
        $event = $this->option('event');

        return array_values(array_filter($events, function (array $delivery) use ($since, $status, $event): bool {
            if ($this->option('failed') && ! DeliveryHealth::problematic($delivery)) {
                return false;
            }

            if (is_scalar($status) && (string) $status !== '' && self::text($delivery, 'status') !== (string) $status) {
                return false;
            }

            if (is_scalar($event) && (string) $event !== '' && self::text($delivery, 'event_type') !== (string) $event) {
                return false;
            }

            if ($since !== null) {
                $created = $delivery['created_at'] ?? null;
                $at = is_scalar($created) ? strtotime((string) $created) : false;

                // An undateable row is KEPT. Dropping it would hide a delivery because
                // its timestamp was odd, which is the wrong direction for a diagnostic.
                if ($at !== false && $at < $since) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Null for no filter, false for unparseable. */
    private function resolveSince(): int|false|null
    {
        $since = $this->option('since');

        if (! is_scalar($since) || (string) $since === '') {
            return null;
        }

        $value = (string) $since;

        // 2h / 3d / 90m, the shorthand anyone reaches for first.
        if (preg_match('/^(\d+)\s*([hdm])$/i', trim($value), $m) === 1) {
            $unit = ['h' => 3600, 'd' => 86400, 'm' => 60][strtolower($m[2])];

            return time() - ((int) $m[1] * $unit);
        }

        return strtotime($value);
    }

    /** @param list<array<string, mixed>> $events */
    private function render(array $events): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($events === []) {
            $this->components->info('No deliveries match.');

            return;
        }

        $this->table(
            ['when', 'event', 'status', 'try', 'code', 'error'],
            array_map(static function (array $d): array {
                return [
                    self::text($d, 'created_at'),
                    self::text($d, 'event_type'),
                    self::text($d, 'status'),
                    self::text($d, 'attempts'),
                    // Null means no HTTP exchange happened at all, which is a different
                    // failure from a bad status and should not read as a blank cell.
                    ($d['response_code'] ?? null) === null ? '—' : self::text($d, 'response_code'),
                    mb_strimwidth(self::text($d, 'error_message'), 0, 60, '…'),
                ];
            }, $events),
        );
    }

    /**
     * A wire value as display text; anything non-scalar reads as absent.
     *
     * @param  array<string, mixed>  $delivery
     */
    private static function text(array $delivery, string $key): string
    {
        $value = $delivery[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
