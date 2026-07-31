<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Scaffolds listener classes for the package's webhook events.
 *
 * Laravel 11+ auto-discovers listeners by their handle() type-hint, so nothing needs
 * registering — the generated class works the moment it exists.
 *
 * Every stub carries the at-least-once caveat in a comment, because that is the contract
 * these listeners run under and it is the single most common way an integration goes
 * wrong: a handler that assumes exactly-once will double-fulfil an order the first time a
 * dispatch throws and WLCM redelivers.
 */
final class MakeWebhookListenersCommand extends Command
{
    /** Usage error: the operator got it wrong, as opposed to the server saying no. */
    private const int USAGE = 2;

    /** Event short name => the payload type its handle() receives. */
    private const array EVENTS = [
        'PaymentSucceeded' => 'WebhookPayload',
        'PaymentCancelled' => 'WebhookPayload',
        'SubscriptionActivated' => 'SubscriptionWebhookPayload',
        'SubscriptionCharged' => 'SubscriptionWebhookPayload',
        'SubscriptionChargeFailed' => 'SubscriptionWebhookPayload',
        'SubscriptionCancelled' => 'SubscriptionWebhookPayload',
        'WebhookReceived' => 'WebhookPayload|SubscriptionWebhookPayload',
    ];

    protected $signature = 'wlcm:webhook:listeners
        {--events= : Comma separated event names; all when omitted}
        {--force : Overwrite existing files}';

    protected $description = 'Scaffold listeners for the WLCM webhook events';

    public function handle(Filesystem $files): int
    {
        $wanted = $this->wantedEvents();

        if ($wanted === null) {
            return self::USAGE;
        }

        $directory = $this->laravel->basePath('app/Listeners/Wlcm');
        $files->ensureDirectoryExists($directory);

        $created = [];
        $skipped = [];

        foreach ($wanted as $event) {
            $path = $directory.'/'.$event.'Listener.php';

            // Idempotent by default: re-running must never silently discard a handler
            // someone has already written.
            if ($files->exists($path) && ! $this->option('force')) {
                $skipped[] = $event;

                continue;
            }

            $files->put($path, $this->stub($event, self::EVENTS[$event]));
            $created[] = $event;
        }

        $this->components->info('app/Listeners/Wlcm');
        $this->line('  created: '.($created === [] ? '(none)' : implode(', ', $created)));
        $this->line('  skipped: '.($skipped === [] ? '(none)' : implode(', ', $skipped).'  — pass --force to overwrite'));

        if ($created !== []) {
            $this->newLine();
            $this->components->warn('Handlers must be idempotent: delivery is at-least-once, not exactly-once.');
        }

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function wantedEvents(): ?array
    {
        $option = $this->option('events');

        if (! is_scalar($option) || (string) $option === '') {
            return array_keys(self::EVENTS);
        }

        $names = array_values(array_filter(
            array_map(trim(...), explode(',', (string) $option)),
            static fn (string $n): bool => $n !== '',
        ));

        $unknown = array_diff($names, array_keys(self::EVENTS));

        if ($unknown !== []) {
            // Naming an event that does not exist is a typo, and generating nothing for
            // it silently is how someone waits for a listener that will never fire.
            $this->components->error('Unknown event(s): '.implode(', ', $unknown));
            $this->line('  known: '.implode(', ', array_keys(self::EVENTS)));

            return null;
        }

        return $names;
    }

    private function stub(string $event, string $payloadType): string
    {
        $imports = ['use Uzbek\\Wlcm\\Events\\'.$event.';'];

        foreach (explode('|', $payloadType) as $type) {
            $imports[] = 'use Uzbek\\Wlcm\\Webhooks\\'.$type.';';
        }

        sort($imports);
        $importBlock = implode("\n", $imports);

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Listeners\\Wlcm;

        {$importBlock}

        final class {$event}Listener
        {
            /**
             * Handle the event.
             *
             * MUST BE IDEMPOTENT. Delivery is at-least-once, never exactly-once: if
             * anything in the dispatch throws, the claim is released and the next
             * redelivery runs this again. A worker killed outright leaves the claim to
             * expire and the same delivery re-dispatches after wlcm.webhook.lease_seconds.
             *
             * Do not trust the payload's amount or external_id — neither is covered by the
             * signature. Reconcile with Wlcm::partner()->orders()->status(\$orderId) before
             * fulfilling anything.
             *
             * @param  {$payloadType}  \$payload
             */
            public function handle({$event} \$event): void
            {
                \$payload = \$event->payload;

                // Your code here.
            }
        }

        PHP;
    }
}
