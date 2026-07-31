<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\WebhookInfo;
use Uzbek\Wlcm\Support\ConfigToggle;
use Uzbek\Wlcm\Support\ConnectionConfig;
use Uzbek\Wlcm\Webhooks\DeliveryHealth;
use Uzbek\Wlcm\Webhooks\IpAllowlist;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

/**
 * One command that answers "is this installation actually going to work".
 *
 * Every check here exists because the failure it looks for is SILENT: a cached config
 * that ignores a rotated secret, a webhook registered at a url WLCM cannot resolve, a
 * storage toggle switched on without the migration behind it. None of these announce
 * themselves — they present as payments that quietly never reconcile.
 *
 * Exit 1 on any failure so it can gate a deploy. A warning never fails: it means
 * "correct, but you should know", and a doctor that fails on those stops being run.
 */
final class DoctorCommand extends Command
{
    private const string OK = 'ok';

    private const string WARN = 'warn';

    private const string FAIL = 'fail';

    protected $signature = 'wlcm:doctor
        {--connection= : Target connection (default: wlcm.default)}
        {--skip-api : Config and routing only; make no outbound calls}
        {--json : Machine readable}';

    protected $description = 'Check a WLCM installation for the failures that stay silent';

    /** @var list<array{group: string, check: string, status: string, detail: string}> */
    private array $results = [];

    public function handle(Repository $config, Router $router): int
    {
        $connection = $this->connectionName($config);

        $this->checkConfig($config, $connection);
        $this->checkCaches();
        $this->checkStorage($config);
        $this->checkRoute($config, $router);

        if (! $this->option('skip-api')) {
            $this->checkApi($connection);
        }

        return $this->report();
    }

    private function checkConfig(Repository $config, string $connection): void
    {
        $block = ConnectionConfig::block($config, $connection);

        if ($block === null) {
            $this->add('config', 'connection', self::FAIL, "wlcm.connections.{$connection} does not exist");

            return;
        }

        $this->add('config', 'connection', self::OK, $connection);

        $partner = is_array($block['partner'] ?? null) ? $block['partner'] : [];

        foreach (['base_url', 'api_key', 'api_secret'] as $key) {
            $value = $partner[$key] ?? null;
            $this->add(
                'config',
                $key,
                is_string($value) && $value !== '' ? self::OK : self::FAIL,
                is_string($value) && $value !== '' ? $this->mask($key, $value) : 'missing',
            );
        }

        $secret = $partner['webhook_secret'] ?? null;
        $this->add(
            'config',
            'webhook_secret',
            is_string($secret) && $secret !== '' ? self::OK : self::WARN,
            is_string($secret) && $secret !== ''
                ? $this->mask('webhook_secret', $secret)
                : 'missing — every delivery will 403',
        );

        // Each of these throws on an unreadable value, which is the point: a typo here
        // is a public endpoint serving on a value the package cannot interpret.
        foreach ([
            'webhook.enabled' => 'wlcm.webhook.enabled',
            'storage.transactions' => 'wlcm.storage.transactions',
        ] as $label => $key) {
            try {
                $on = ConfigToggle::enabled($config->get($key, false), $key);
                $this->add('config', $label, self::OK, $on ? 'on' : 'off');
            } catch (Throwable $e) {
                $this->add('config', $label, self::FAIL, $e->getMessage());
            }
        }

        $this->checkWindow($config, 'webhook.tolerance_seconds', 'wlcm.webhook.tolerance_seconds', nullable: true);
        $this->checkWindow($config, 'webhook.lease_seconds', 'wlcm.webhook.lease_seconds', nullable: false);

        $allowed = $config->get('wlcm.webhook.allowed_ips');

        if (! IpAllowlist::configured($allowed)) {
            $this->add('config', 'webhook.allowed_ips', self::OK, 'off');
        } else {
            try {
                // Matching a throwaway address validates every entry.
                IpAllowlist::allows('192.0.2.1', $allowed);
                $this->add('config', 'webhook.allowed_ips', self::OK, 'parses');
            } catch (Throwable $e) {
                $this->add('config', 'webhook.allowed_ips', self::FAIL, $e->getMessage());
            }
        }
    }

    private function checkWindow(Repository $config, string $label, string $key, bool $nullable): void
    {
        $value = $config->get($key);

        if ($value === null) {
            $this->add(
                'config',
                $label,
                $nullable ? self::WARN : self::FAIL,
                $nullable ? 'disabled — replays inside any window verify' : 'null is not allowed here',
            );

            return;
        }

        $this->add(
            'config',
            $label,
            is_numeric($value) && (int) $value > 0 ? self::OK : self::FAIL,
            is_numeric($value) && (int) $value > 0 ? (string) (int) $value.'s' : 'not a positive number',
        );
    }

    private function checkCaches(): void
    {
        // Both are documented traps: config:cache freezes env(), so a rotated secret in
        // .env alone is inert, and route:cache bakes the webhook path.
        $this->add(
            'caches',
            'config:cache',
            $this->laravel->configurationIsCached() ? self::WARN : self::OK,
            $this->laravel->configurationIsCached()
                ? 'active — .env edits are inert until you rebuild it'
                : 'not cached',
        );

        $this->add(
            'caches',
            'route:cache',
            $this->laravel->routesAreCached() ? self::WARN : self::OK,
            $this->laravel->routesAreCached()
                ? 'active — a changed webhook path needs route:cache rebuilt'
                : 'not cached',
        );
    }

    private function checkStorage(Repository $config): void
    {
        try {
            $logs = ConfigToggle::logsEnabled($config->get('wlcm.storage.logs', false));
            $driver = $config->get('wlcm.storage.logs');
            $this->add('storage', 'logs', self::OK, $logs ? (is_scalar($driver) ? (string) $driver : 'on') : 'off');
        } catch (Throwable $e) {
            $this->add('storage', 'logs', self::FAIL, $e->getMessage());
            $logs = false;
            $driver = null;
        }

        // Guarded: an unreadable toggle THROWS, and a doctor that crashes on the very
        // fault it exists to diagnose is worse than no doctor. checkConfig already
        // reported it, so here it simply means "cannot tell whether the table is needed".
        try {
            $transactions = ConfigToggle::enabled($config->get('wlcm.storage.transactions', false), 'wlcm.storage.transactions');
        } catch (Throwable) {
            $transactions = false;
        }

        // A toggle switched on without the migration behind it means every audit write
        // is swallowed by report() and the trail is silently empty.
        foreach ([
            'wlcm_transactions' => $transactions,
            'wlcm_logs' => $logs && $driver === 'database',
        ] as $table => $needed) {
            if (! $needed) {
                continue;
            }

            try {
                $this->add(
                    'storage',
                    $table,
                    Schema::hasTable($table) ? self::OK : self::FAIL,
                    Schema::hasTable($table) ? 'present' : 'enabled but the table is missing — run migrate',
                );
            } catch (Throwable $e) {
                $this->add('storage', $table, self::FAIL, 'cannot reach the database: '.$e->getMessage());
            }
        }
    }

    private function checkRoute(Repository $config, Router $router): void
    {
        $enabled = false;

        try {
            $enabled = ConfigToggle::enabled($config->get('wlcm.webhook.enabled', true), 'wlcm.webhook.enabled');
        } catch (Throwable) {
            // Already reported by checkConfig; nothing to add here.
        }

        if (! $enabled) {
            $this->add('route', 'registered', self::WARN, 'webhooks are switched off');

            return;
        }

        $uri = WebhookRoute::uri($config->get('wlcm.webhook.path'));
        $domain = WebhookRoute::domain($config->get('wlcm.webhook.domain'));

        $this->add('route', 'uri', self::OK, ($domain !== null ? $domain.'/' : '/').$uri);

        $connections = array_map(strval(...), array_keys((array) $config->get('wlcm.connections', [])));
        $shadow = WebhookRoute::shadow($router, $config->get('wlcm.webhook.path'), $connections);

        $this->add(
            'route',
            'shadowed',
            $shadow === null ? self::OK : self::FAIL,
            $shadow === null
                ? 'nothing else serves this path'
                : 'another route serves it WITHOUT signature verification: '.$shadow->uri(),
        );
    }

    private function checkApi(string $connection): void
    {
        try {
            $me = Wlcm::connection($connection)->partner()->partners()->me();
            $this->add('api', 'auth', self::OK, "{$me->name} (id {$me->id})");
        } catch (Throwable $e) {
            // Without auth nothing below can run, and the cause is almost always a
            // credential or base_url problem already reported above.
            $this->add('api', 'auth', self::FAIL, $e->getMessage());

            return;
        }

        try {
            $webhooks = Wlcm::connection($connection)->partner()->webhooks()->all();
        } catch (Throwable $e) {
            $this->add('api', 'webhooks', self::FAIL, $e->getMessage());

            return;
        }

        if ($webhooks === []) {
            $this->add('api', 'webhooks', self::WARN, 'none registered — WLCM has nowhere to deliver');

            return;
        }

        foreach ($webhooks as $webhook) {
            $this->checkWebhook($connection, $webhook);
        }
    }

    private function checkWebhook(string $connection, WebhookInfo $webhook): void
    {
        $label = '#'.$webhook->id;
        $host = strtolower((string) parse_url($webhook->url, PHP_URL_HOST));

        // A url WLCM cannot resolve registers perfectly and then never delivers. This is
        // the exact shape that had a real partner on `wb.com` failing every delivery.
        if (! str_contains($webhook->url, '://')) {
            $this->add('webhooks', $label, self::FAIL, "{$webhook->url} has no scheme — WLCM cannot build a request");
        } elseif ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test')
            || str_ends_with($host, '.local') || str_ends_with($host, '.invalid') || str_ends_with($host, '.localhost')) {
            $this->add('webhooks', $label, self::FAIL, "{$host} is not reachable from the internet");
        } elseif ($webhook->isActive === false) {
            $this->add('webhooks', $label, self::WARN, 'inactive at WLCM');
        } else {
            $this->add('webhooks', $label, self::OK, $webhook->url);
        }

        // Registered somewhere this app does not serve is a silent 404 per delivery.
        $ourPath = '/'.WebhookRoute::path(config('wlcm.webhook.path'));
        $theirPath = rtrim((string) parse_url($webhook->url, PHP_URL_PATH), '/');

        if ($theirPath !== '' && rtrim($ourPath, '/') !== $theirPath && ! str_starts_with($theirPath, rtrim($ourPath, '/').'/')) {
            $this->add('webhooks', $label.' path', self::WARN, "{$theirPath} is not the path this app serves ({$ourPath})");
        }

        try {
            $events = Wlcm::connection($connection)->partner()->webhooks()->events($webhook->id);
        } catch (Throwable) {
            // History is a nice-to-have; its absence is not a fault of the installation.
            return;
        }

        $recent = array_slice($events, 0, 10);
        $bad = array_values(array_filter($recent, DeliveryHealth::problematic(...)));

        if ($recent === []) {
            return;
        }

        $this->add(
            'webhooks',
            $label.' deliveries',
            $bad === [] ? self::OK : self::FAIL,
            $bad === []
                ? 'last '.count($recent).' delivered cleanly'
                : count($bad).' of the last '.count($recent).' failed — wlcm:webhook:events '.$webhook->id.' --failed',
        );
    }

    private function report(): int
    {
        $failed = array_filter($this->results, static fn (array $r): bool => $r['status'] === self::FAIL);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $failed === [],
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['', 'group', 'check', 'detail'],
            array_map(static fn (array $r): array => [
                match ($r['status']) {
                    self::OK => '<fg=green>ok</>',
                    self::WARN => '<fg=yellow>warn</>',
                    default => '<fg=red>FAIL</>',
                },
                $r['group'],
                $r['check'],
                $r['detail'],
            ], $this->results),
        );

        if ($failed === []) {
            $this->components->info('No failures.');

            return self::SUCCESS;
        }

        $this->components->error(count($failed).' check(s) failed.');

        return self::FAILURE;
    }

    private function add(string $group, string $check, string $status, string $detail): void
    {
        $this->results[] = compact('group', 'check', 'status', 'detail');
    }

    private function connectionName(Repository $config): string
    {
        $option = $this->option('connection');

        if (is_scalar($option) && (string) $option !== '') {
            return (string) $option;
        }

        $default = $config->get('wlcm.default', 'main');

        return is_scalar($default) ? (string) $default : 'main';
    }

    /**
     * Doctor output is the thing people paste into support tickets, so a SECRET shows
     * only its length — never a prefix. The api key is an identifier rather than a
     * credential and support needs it to find the partner, so that one keeps a prefix.
     */
    private function mask(string $key, string $value): string
    {
        return match ($key) {
            'base_url' => $value,
            'api_key' => strlen($value) <= 8 ? '***' : substr($value, 0, 8).'***',
            default => 'set ('.strlen($value).' chars)',
        };
    }
}
