<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console;

use Illuminate\Console\Command;
use Throwable;
use Uzbek\Wlcm\Console\Concerns\ResolvesWebhook;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Support\EnvWriter;
use Uzbek\Wlcm\Webhooks\WebhookRoute;

/**
 * Registers this application's webhook endpoint with WLCM.
 *
 * The url is DERIVED rather than asked for cold: the package already knows the path it
 * serves and the host it expects, and an operator typing it from memory is how a webhook
 * ends up pointing one segment away from the route that would have verified it.
 */
final class WebhookCreateCommand extends Command
{
    use ResolvesWebhook;

    /** Usage error: the operator got it wrong, as opposed to the server saying no. */
    private const int USAGE = 2;

    protected $signature = 'wlcm:webhook:create
        {--url= : Full delivery url; skips derivation entirely}
        {--host= : Host to build the url from (default: wlcm.webhook.domain or APP_URL)}
        {--secret= : Signing secret; a strong one is generated when omitted}
        {--connection= : Target connection (default: wlcm.default)}
        {--json : Raw server object instead of a table}';

    protected $description = 'Register this application\'s webhook endpoint with WLCM';

    public function handle(): int
    {
        $url = $this->resolveUrl();

        if ($url === null) {
            return self::USAGE;
        }

        if (! $this->warnIfUnreachable($url)) {
            return self::SUCCESS;
        }

        $secretOption = $this->option('secret');
        $secret = is_scalar($secretOption) && (string) $secretOption !== ''
            ? (string) $secretOption
            // 32 random bytes hex-encoded. Generated rather than prompted because a
            // human-chosen webhook secret is the weakest link in the signature scheme.
            : bin2hex(random_bytes(32));

        $this->components->info("Registering {$url}");

        if (! $this->option('no-interaction') && ! $this->confirm('Create this webhook at WLCM?', true)) {
            $this->components->info('Nothing created.');

            return self::SUCCESS;
        }

        try {
            $webhook = Wlcm::connection($this->connectionName())->partner()->webhooks()->create($url, $secret);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderWebhooks([$webhook]);

        // The server may mint its own secret and ignore ours; the one it reports back is
        // the one deliveries will be signed with, so that is what gets stored.
        $this->storeSecret($webhook->secretKey ?? $secret);

        return self::SUCCESS;
    }

    private function resolveUrl(): ?string
    {
        $option = $this->option('url');

        if (is_scalar($option) && (string) $option !== '') {
            return (string) $option;
        }

        $host = $this->resolveHost();

        if ($host === null) {
            $this->components->error('No host. Pass --host or --url, or set wlcm.webhook.domain or APP_URL.');

            return null;
        }

        $path = WebhookRoute::path(config('wlcm.webhook.path'));
        $connection = $this->connectionName();
        $default = config('wlcm.default', 'main');

        // The connection segment is only added for a non-default connection: our route
        // makes it optional, and a bare url is what the default connection answers on.
        $segment = $connection === (is_scalar($default) ? (string) $default : 'main') ? '' : '/'.$connection;

        $url = rtrim($host, '/').'/'.$path.$segment;

        if ($this->option('no-interaction')) {
            return $url;
        }

        $answer = $this->ask('Delivery url', $url);

        return is_scalar($answer) && (string) $answer !== '' ? (string) $answer : $url;
    }

    private function resolveHost(): ?string
    {
        $option = $this->option('host');

        if (is_scalar($option) && (string) $option !== '') {
            return $this->withScheme((string) $option);
        }

        $domain = WebhookRoute::domain(config('wlcm.webhook.domain'));

        if ($domain !== null) {
            return $this->withScheme($domain);
        }

        $appUrl = config('app.url');

        return is_scalar($appUrl) && (string) $appUrl !== '' ? (string) $appUrl : null;
    }

    private function withScheme(string $host): string
    {
        return str_contains($host, '://') ? $host : 'https://'.$host;
    }

    /** False when the operator declined to continue. */
    private function warnIfUnreachable(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $unreachable = $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost');

        if (! $unreachable) {
            return true;
        }

        // WLCM delivers from its own network; a host only your machine can resolve will
        // register fine and then never receive anything.
        $this->components->warn("{$host} is not reachable from the internet. WLCM will accept this endpoint and never deliver to it.");

        return $this->option('no-interaction') || $this->confirm('Continue anyway?', false);
    }

    private function storeSecret(string $secret): void
    {
        $connection = $this->connectionName();
        $default = config('wlcm.default', 'main');

        if ($connection !== (is_scalar($default) ? (string) $default : 'main')) {
            // Only the default connection reads WLCM_WEBHOOK_SECRET. Writing it for
            // another connection would arm the wrong one and read as success.
            $this->newLine();
            $this->components->warn("Connection \"{$connection}\" does not read WLCM_WEBHOOK_SECRET. Nothing was written to .env.");
            $this->line("Set it in config/wlcm.php under connections.{$connection}.partner.webhook_secret:");
            $this->newLine();
            $this->line("    'webhook_secret' => '{$secret}',");

            return;
        }

        $writer = new EnvWriter($this->laravel->environmentFilePath());

        if (! $writer->has('WLCM_WEBHOOK_SECRET')) {
            $writer->append('WLCM_WEBHOOK_SECRET', $secret);
            $this->components->info('WLCM_WEBHOOK_SECRET written to .env');

            return;
        }

        // Never overwrite: the existing value may be signing live deliveries for an
        // endpoint that is still registered.
        $this->components->warn('WLCM_WEBHOOK_SECRET already exists in .env and was NOT changed.');
        $this->line('  new secret: '.$secret);

        if (! $this->option('no-interaction') && $this->confirm('Append a commented duplicate?', false)) {
            $writer->append('WLCM_WEBHOOK_SECRET', $secret, commented: true);
            $this->components->info('Appended as a comment.');
        }
    }
}
