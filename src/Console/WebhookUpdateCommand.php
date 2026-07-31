<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console;

use Illuminate\Console\Command;
use Throwable;
use Uzbek\Wlcm\Console\Concerns\ResolvesWebhook;
use Uzbek\Wlcm\Facades\Wlcm;

final class WebhookUpdateCommand extends Command
{
    use ResolvesWebhook;

    /** Usage error: the operator got it wrong, as opposed to the server saying no. */
    private const int USAGE = 2;

    protected $signature = 'wlcm:webhook:update
        {id? : Webhook id; prompts from the live list when omitted}
        {--url= : New delivery url}
        {--events= : Comma separated event list}
        {--active : Mark the endpoint active}
        {--inactive : Mark the endpoint inactive}
        {--connection= : Target connection (default: wlcm.default)}
        {--json : Raw server object instead of a table}';

    protected $description = 'Update a registered webhook';

    public function handle(): int
    {
        if ($this->option('active') && $this->option('inactive')) {
            $this->components->error('--active and --inactive contradict each other.');

            return self::USAGE;
        }

        $argument = $this->argument('id');
        $id = $this->resolveWebhookId(is_numeric($argument) ? (int) $argument : null);

        if ($id === null) {
            return self::USAGE;
        }

        $attributes = [];

        $url = $this->option('url');
        if (is_scalar($url) && (string) $url !== '') {
            $attributes['url'] = (string) $url;
        }

        $events = $this->option('events');
        if (is_scalar($events) && (string) $events !== '') {
            $attributes['events'] = array_values(array_filter(
                array_map(trim(...), explode(',', (string) $events)),
                static fn (string $e): bool => $e !== '',
            ));
        }

        if ($this->option('active')) {
            $attributes['is_active'] = true;
        }

        if ($this->option('inactive')) {
            $attributes['is_active'] = false;
        }

        // An empty PATCH would round-trip to the server and report success while changing
        // nothing — a usage error, not an update.
        if ($attributes === []) {
            $this->components->error('Nothing to update. Pass --url, --events, --active or --inactive.');

            return self::USAGE;
        }

        try {
            $updated = Wlcm::connection($this->connectionName())->partner()->webhooks()->update($id, $attributes);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderWebhooks([$updated]);

        return self::SUCCESS;
    }
}
