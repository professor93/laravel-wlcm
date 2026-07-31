<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console;

use Illuminate\Console\Command;
use Throwable;
use Uzbek\Wlcm\Console\Concerns\ResolvesWebhook;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\WebhookInfo;

final class WebhookDeleteCommand extends Command
{
    use ResolvesWebhook;

    /** Usage error: the operator got it wrong, as opposed to the server saying no. */
    private const int USAGE = 2;

    protected $signature = 'wlcm:webhook:delete
        {id? : Webhook id; prompts from the live list when omitted}
        {--force : Skip confirmation, and allow deleting this app\'s own endpoint}
        {--connection= : Target connection (default: wlcm.default)}';

    protected $description = 'Delete a registered webhook';

    public function handle(): int
    {
        $argument = $this->argument('id');
        $id = $this->resolveWebhookId(is_numeric($argument) ? (int) $argument : null);

        if ($id === null) {
            return self::USAGE;
        }

        try {
            $webhooks = $this->webhooks();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $target = null;

        foreach ($webhooks as $webhook) {
            if ($webhook->id === $id) {
                $target = $webhook;
            }
        }

        if (! $target instanceof WebhookInfo) {
            $this->components->error("No webhook with id {$id} on this partner.");

            return self::USAGE;
        }

        // Deleting the endpoint this app serves stops every delivery reaching it, and the
        // symptom is silence — no error, just payments that never get reconciled. Worth a
        // deliberate --force rather than a yes/no anyone can tab through.
        if ($this->isOwnEndpoint($target->url) && ! $this->option('force')) {
            $this->components->error("#{$id} {$target->url} is this application's own webhook endpoint.");
            $this->components->warn('Deleting it stops all deliveries. Re-run with --force if that is intended.');

            return self::USAGE;
        }

        if (! $this->option('force')) {
            // Non-interactive means there is no prompt to answer, so without --force
            // nothing would confirm the deletion at all — an unattended run would delete
            // on nothing but an id.
            if ($this->option('no-interaction')) {
                $this->components->error('Refusing to delete without confirmation. Pass --force in non-interactive mode.');

                return self::USAGE;
            }

            if (! $this->confirm("Delete webhook #{$id} ({$target->url})?", false)) {
                $this->components->info('Nothing deleted.');

                return self::SUCCESS;
            }
        }

        try {
            Wlcm::connection($this->connectionName())->partner()->webhooks()->delete($id);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Deleted webhook #{$id}.");

        return self::SUCCESS;
    }
}
