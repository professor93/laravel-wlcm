<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console\Concerns;

use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\WebhookInfo;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

/**
 * Shared plumbing for the webhook commands: which connection, which webhook, and whether
 * a given url is the endpoint this application serves.
 */
trait ResolvesWebhook
{
    private function connectionName(): string
    {
        $option = $this->option('connection');

        if (is_scalar($option) && (string) $option !== '') {
            return (string) $option;
        }

        $default = config('wlcm.default', 'main');

        return is_scalar($default) ? (string) $default : 'main';
    }

    /** @return list<WebhookInfo> */
    private function webhooks(): array
    {
        return Wlcm::connection($this->connectionName())->partner()->webhooks()->all();
    }

    /**
     * The id from the option, or a picker over the live list. Null means the operator
     * cannot be asked and did not say — the caller returns USAGE.
     */
    private function resolveWebhookId(?int $given): ?int
    {
        if ($given !== null) {
            return $given;
        }

        if ($this->option('no-interaction')) {
            $this->components->error('An id is required in non-interactive mode.');

            return null;
        }

        $webhooks = $this->webhooks();

        if ($webhooks === []) {
            $this->components->warn('This partner has no webhooks.');

            return null;
        }

        $choices = [];

        foreach ($webhooks as $webhook) {
            $choices[(string) $webhook->id] = "#{$webhook->id}  {$webhook->url}";
        }

        $picked = $this->choice('Which webhook?', $choices);

        // choice() hands back the LABEL, so map it back rather than casting a label to
        // int and getting 0. The key is an int, not the string it was written as: PHP
        // silently casts a numeric-string array key to an integer.
        $id = array_search($picked, $choices, true);

        return is_int($id) ? $id : null;
    }

    /**
     * Is this url the endpoint this app serves? Compared on host+path only: a scheme or
     * trailing-slash difference is not a different endpoint, and treating it as one would
     * let the delete guard be bypassed by a typo.
     */
    private function isOwnEndpoint(string $url): bool
    {
        $ourPath = '/'.WebhookRoute::path(config('wlcm.webhook.path'));

        $theirPath = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $theirHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (rtrim($ourPath, '/') !== $theirPath && ! str_starts_with($theirPath, rtrim($ourPath, '/').'/')) {
            return false;
        }

        $domain = WebhookRoute::domain(config('wlcm.webhook.domain'));
        $appUrl = config('app.url');
        $ourHost = strtolower((string) ($domain ?? parse_url(is_scalar($appUrl) ? (string) $appUrl : '', PHP_URL_HOST)));

        // With no host of our own to compare, the path match alone decides — better to
        // ask for --force on someone else's endpoint than to delete our own in silence.
        return $ourHost === '' || $ourHost === $theirHost;
    }

    /** @param list<WebhookInfo> $webhooks */
    private function renderWebhooks(array $webhooks): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(static fn (WebhookInfo $w): array => $w->raw, $webhooks),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        if ($webhooks === []) {
            $this->components->info('No webhooks.');

            return;
        }

        $this->table(
            ['id', 'url', 'active', 'events', 'last triggered', 'last status'],
            array_map(static fn (WebhookInfo $w): array => [
                (string) $w->id,
                $w->url,
                $w->isActive === null ? '?' : ($w->isActive ? 'yes' : 'no'),
                $w->events === null ? '' : implode(', ', array_map(
                    static fn (mixed $e): string => is_scalar($e) ? (string) $e : '?',
                    $w->events,
                )),
                (string) $w->lastTriggeredAt,
                $w->lastStatusCode === null ? '' : (string) $w->lastStatusCode,
            ], $webhooks),
        );
    }
}
