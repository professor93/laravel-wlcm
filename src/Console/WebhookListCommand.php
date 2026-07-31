<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console;

use Illuminate\Console\Command;
use Throwable;
use Uzbek\Wlcm\Console\Concerns\ResolvesWebhook;
use Uzbek\Wlcm\Partner\Data\WebhookInfo;

final class WebhookListCommand extends Command
{
    use ResolvesWebhook;

    protected $signature = 'wlcm:webhook:list
        {--connection= : Target connection (default: wlcm.default)}
        {--active-only : Only endpoints WLCM reports as active}
        {--json : Raw server objects instead of a table}';

    protected $description = 'List the webhooks registered for this partner';

    public function handle(): int
    {
        try {
            $webhooks = $this->webhooks();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('active-only')) {
            // Only a definite false is filtered out: a null means WLCM did not say, and
            // hiding an endpoint we cannot classify would misrepresent the list.
            $webhooks = array_values(array_filter(
                $webhooks,
                static fn (WebhookInfo $w): bool => $w->isActive !== false,
            ));
        }

        $this->renderWebhooks($webhooks);

        return self::SUCCESS;
    }
}
