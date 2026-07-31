<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Partner\Data\OnboardingCredentials;
use Uzbek\Wlcm\Support\EnvWriter;

/**
 * Bootstraps a partner from an onboarding token.
 *
 * The api_secret is shown ONCE by the server and never again, so the ordering here is
 * deliberate: the credentials are printed before anything that can fail. A .env write,
 * a cached config or a verify call going wrong must never be the reason an operator
 * loses a secret they cannot ask for a second time.
 */
final class OnboardCommand extends Command
{
    protected $signature = 'wlcm:onboard
        {--token= : Onboarding token issued by WLCM}
        {--name= : Partner name to register (default: app name)}
        {--base-url= : API base url, including /api/v1}
        {--connection= : Target connection (default: wlcm.default)}';

    protected $description = 'Register a WLCM partner from an onboarding token and capture its credentials';

    public function handle(Repository $config): int
    {
        $default = $this->stringConfig($config, 'wlcm.default', 'main');
        $connection = $this->stringOption('connection') ?: $default;
        $isDefault = $connection === $default;

        $baseUrl = $this->resolveBaseUrl($config, $connection);

        if ($baseUrl === null) {
            $this->components->error('No base url. Pass --base-url or set WLCM_BASE_URL.');

            return self::FAILURE;
        }

        $token = $this->stringOption('token');

        // Never prompt under --no-interaction: an unattended run would hang, and the
        // mocked output in tests throws rather than blocking.
        if ($token === '' && ! $this->option('no-interaction')) {
            $answer = $this->secret('Onboarding token');
            $token = is_scalar($answer) ? (string) $answer : '';
        }

        if (trim($token) === '') {
            $this->components->error('A token is required.');

            return self::FAILURE;
        }

        $name = $this->stringOption('name') ?: $this->stringConfig($config, 'app.name', 'Laravel');

        // Point the target connection at the resolved base url without demanding the
        // credentials this command exists to obtain.
        $config->set("wlcm.connections.{$connection}.partner.base_url", $baseUrl);

        $onboarding = Wlcm::connection($connection)->onboarding()->onboarding();

        try {
            if (! $onboarding->validateToken($token)) {
                $this->components->error('WLCM did not accept that token.');

                return self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->components->error('Token check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Token is valid. Registering \"{$name}\" against {$baseUrl}.");

        // Confirm before the call that SPENDS the token — it is single use.
        if (! $this->option('no-interaction') && ! $this->confirm('This consumes the token and creates a partner. Continue?', true)) {
            $this->components->warn('Aborted. The token was not consumed.');

            return self::SUCCESS;
        }

        try {
            $credentials = $onboarding->complete($token, $name);
        } catch (Throwable $e) {
            $this->components->error('Registration failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->showCredentials($credentials);

        if ($isDefault) {
            $this->writeEnv($credentials, $baseUrl);
        } else {
            $this->showConfigBlock($connection, $credentials, $baseUrl);
        }

        $this->warnAboutCachedConfig();
        $this->offerVerification($credentials, $baseUrl);

        return self::SUCCESS;
    }

    private function resolveBaseUrl(Repository $config, string $connection): ?string
    {
        $option = $this->stringOption('base-url');

        if ($option !== '') {
            return $option;
        }

        $configured = $config->get("wlcm.connections.{$connection}.partner.base_url");

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($this->option('no-interaction')) {
            return null;
        }

        $answer = $this->ask('API base url (including /api/v1)');
        $answer = is_scalar($answer) ? (string) $answer : '';

        return $answer === '' ? null : $answer;
    }

    private function showCredentials(OnboardingCredentials $c): void
    {
        $this->newLine();
        $this->components->info('Partner registered. These credentials are shown ONCE.');

        $this->table(['Field', 'Value'], [
            ['id', (string) $c->id],
            ['name', (string) $c->name],
            ['api_key', (string) $c->apiKey],
            ['api_secret', (string) $c->apiSecret],
        ]);

        if ($c->warning !== null && $c->warning !== '') {
            // The server's own wording, verbatim — it is the authority on its own policy.
            $this->components->warn($c->warning);
        }
    }

    private function writeEnv(OnboardingCredentials $c, string $baseUrl): void
    {
        $writer = new EnvWriter($this->laravel->environmentFilePath());

        $added = [];
        $commented = [];
        $skipped = [];

        foreach ([
            'WLCM_BASE_URL' => $baseUrl,
            'WLCM_API_KEY' => (string) $c->apiKey,
            'WLCM_API_SECRET' => (string) $c->apiSecret,
        ] as $key => $value) {
            if ($value === '') {
                continue;
            }

            if (! $writer->has($key)) {
                $writer->append($key, $value);
                $added[] = $key;

                continue;
            }

            // Never overwrite. Show what is there — masked, because it may be a live
            // secret — and let the operator decide.
            $this->components->warn("{$key} already exists: ".$this->mask((string) $writer->value($key)));

            // Non-interactive defaults to NO: an unattended run must not grow the file.
            $duplicate = ! $this->option('no-interaction')
                && $this->confirm("Append a commented duplicate of {$key}?", false);

            if ($duplicate) {
                $writer->append($key, $value, commented: true);
                $commented[] = $key;
            } else {
                $skipped[] = $key;
            }
        }

        $this->newLine();
        $this->components->info('.env written to '.$this->laravel->environmentFilePath());
        $this->line('  added live:        '.($added === [] ? '(none)' : implode(', ', $added)));
        $this->line('  added commented:   '.($commented === [] ? '(none)' : implode(', ', $commented)));
        $this->line('  left untouched:    '.($skipped === [] ? '(none)' : implode(', ', $skipped)));
    }

    private function showConfigBlock(string $connection, OnboardingCredentials $c, string $baseUrl): void
    {
        $this->newLine();
        // Only the default connection has env mappings in the packaged config. Writing
        // WLCM_API_KEY for another connection would configure the WRONG one and read as
        // success — so print what to paste instead.
        $this->components->warn("Connection \"{$connection}\" is not the default, and only the default reads WLCM_* env vars. Nothing was written to .env.");
        $this->line('Add this to config/wlcm.php under connections:');
        $this->newLine();
        $this->line("    '{$connection}' => [");
        $this->line("        'partner' => [");
        $this->line("            'base_url' => '{$baseUrl}',");
        $this->line("            'api_key' => '{$c->apiKey}',");
        $this->line("            'api_secret' => '{$c->apiSecret}',");
        $this->line('        ],');
        $this->line('    ],');
    }

    private function warnAboutCachedConfig(): void
    {
        if (! $this->laravel->configurationIsCached()) {
            return;
        }

        $this->newLine();
        $this->components->warn(
            'Config is cached, so the .env values above are INERT until you run '
            .'`php artisan config:clear` or rebuild the cache. The running app keeps its baked values.'
        );
    }

    private function offerVerification(OnboardingCredentials $c, string $baseUrl): void
    {
        if ($this->option('no-interaction') || ! $this->confirm('Verify the new credentials now?', true)) {
            return;
        }

        // Built from the FRESH values, never from config: under a cached config the
        // config path still holds the old credentials, so verifying through it would
        // test the wrong thing and report a false failure.
        config()->set('wlcm.connections.__wlcm_verify.partner', [
            'base_url' => $baseUrl,
            'api_key' => (string) $c->apiKey,
            'api_secret' => (string) $c->apiSecret,
        ]);

        try {
            $me = Wlcm::connection('__wlcm_verify')->partner()->partners()->me();
            $this->components->info("Verified: authenticated as \"{$me->name}\" (id {$me->id}).");
        } catch (Throwable $e) {
            $this->components->error('Verification failed: '.$e->getMessage());
        }
    }

    private function mask(string $value): string
    {
        return strlen($value) <= 8 ? '***' : substr($value, 0, 8).'***';
    }

    // option() and config()->get() are both mixed; an array-valued option would otherwise
    // fatal on a cast rather than being rejected as the bad input it is.
    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_scalar($value) ? (string) $value : '';
    }

    private function stringConfig(Repository $config, string $key, string $fallback): string
    {
        $value = $config->get($key, $fallback);

        return is_scalar($value) ? (string) $value : $fallback;
    }
}
