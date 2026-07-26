<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use RuntimeException;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Exceptions\ServerException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Models\WlcmLog;
use Uzbek\Wlcm\Storage\DatabaseLogStore;
use Uzbek\Wlcm\Storage\FileLogStore;
use Uzbek\Wlcm\Storage\LogChannelLogStore;
use Uzbek\Wlcm\Storage\LogStore;
use Uzbek\Wlcm\Tests\TestCase;

final class LogDriversTest extends TestCase
{
    use RefreshDatabase;

    private const SCALAR_BODY = '9860991234569999';

    private ?string $dir = null;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('logging.default', 'wlcm_default');
        $app['config']->set('logging.channels.wlcm_default', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $app['config']->set('logging.channels.wlcm_capture', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    }

    /** @return list<LogRecord> */
    private function records(string $channel): array
    {
        $logger = Log::channel($channel);
        $this->assertInstanceOf(IlluminateLogger::class, $logger);
        $monolog = $logger->getLogger();
        $this->assertInstanceOf(MonologLogger::class, $monolog);
        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        return array_values(array_filter(
            $handler->getRecords(),
            static fn (LogRecord $record): bool => $record->message === 'wlcm',
        ));
    }

    protected function tearDown(): void
    {
        foreach ([storage_path('logs/wlcm.jsonl'), $this->emergencyLog()] as $default) {
            if (is_file($default)) {
                unlink($default);
            }
        }

        if ($this->dir !== null && is_dir($this->dir)) {
            self::rmrf($this->dir);
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    private static function rmrf(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) ? self::rmrf($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function dir(): string
    {
        return $this->dir ??= sys_get_temp_dir().'/wlcm-log-drivers-'.bin2hex(random_bytes(6));
    }

    private function path(string $name = 'wlcm.jsonl'): string
    {
        return $this->dir().'/'.$name;
    }

    private function useFileDriver(?string $path = null): string
    {
        $path ??= $this->path();
        config(['wlcm.storage.logs' => 'file', 'wlcm.storage.logs_path' => $path]);

        return $path;
    }

    /** @return list<array<array-key, mixed>> */
    private function lines(string $path): array
    {
        $raw = trim((string) file_get_contents($path));

        return array_map(
            static fn (string $line): array => (array) json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $raw), static fn (string $l): bool => $l !== '')),
        );
    }

    /** @return list<array{level: string, message: string, context: array<array-key, mixed>}> */
    private function captureLogContexts(callable $callback): array
    {
        $contexts = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$contexts): void {
            $contexts[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });

        $callback();

        return $contexts;
    }

    private function emergencyLog(): string
    {
        return storage_path('logs/laravel.log');
    }

    private function scalarBodyCheckout(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response('"'.self::SCALAR_BODY.'"', 200)]);

        try {
            $this->checkout();
            $this->fail('A scalar 200 body must not read as a success.');
        } catch (ServerException $e) {
            $this->assertSame(200, $e->responseStatus);
        }
    }

    /** @return array{string, string, string, string, array<string, mixed>, array<string, mixed>, int, int, array<string, string|null>, string} */
    private function outArgs(): array
    {
        return [
            'main', 'partner', 'POST', 'https://api.wlcm.test/api/v1/checkout',
            ['amount' => 1200], ['order_id' => 1254], 201, 12, [], 'ok',
        ];
    }

    private function fakeCheckout(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-9', 'state' => 0,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);
    }

    private function checkout(): void
    {
        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');
    }

    private function cardCheckout(): void
    {
        Http::fake(['api.wlcm.test/*' => Http::response(['order_id' => 1, 'state' => 1], 201)]);

        Wlcm::partner()->checkout()->create(
            amountTiyin: 120000, externalId: 'ORD-9', provider: 'card',
            cardNumber: '9860991234569999', expireDate: '3003',
        );
    }

    /** @return array<string, string> */
    private function webhookData(): array
    {
        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', "1254:7788:2:{$data['timestamp']}", 'whsec_test');

        return $data;
    }

    /** @return array<string, int|string> */
    private function subscriptionWebhookData(): array
    {
        $data = [
            'event' => 'subscription.charged', 'external_order_id' => 'order-1001',
            'external_user_id' => 'user-1001', 'subscription_id' => 8,
            'amount' => '49900.00', 'status' => 'active', 'timestamp' => 1745740800000,
        ];
        $data['signature'] = hash_hmac('sha256', 'order-1001:subscription.charged:1745740800000', 'whsec_test');

        return $data;
    }

    public function test_database_driver_files_a_subscription_delivery_under_its_own_id(): void
    {
        config(['wlcm.storage.logs' => 'database']);

        $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData())->assertOk();

        $row = WlcmLog::sole();
        $this->assertSame('order-1001', $row->external_id);
        $this->assertNull($row->order_id);
        $this->assertNull($row->payment_id);
    }

    public function test_file_driver_files_a_subscription_delivery_under_its_own_id(): void
    {
        $path = $this->useFileDriver();

        $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData())->assertOk();

        $entry = $this->lines($path)[0];
        $this->assertSame('order-1001', $entry['external_id']);
        $this->assertNull($entry['order_id']);
        $this->assertNull($entry['payment_id']);
    }

    public function test_log_driver_files_a_subscription_delivery_under_its_own_id(): void
    {
        config(['wlcm.storage.logs' => 'log']);

        $logged = $this->captureLogContexts(function (): void {
            $this->postJson('/wlcm/webhook', $this->subscriptionWebhookData())->assertOk();
        });

        $this->assertCount(1, $logged);
        $this->assertSame('order-1001', $logged[0]['context']['external_id']);
        $this->assertNull($logged[0]['context']['order_id']);
        $this->assertNull($logged[0]['context']['payment_id']);
    }

    public function test_payment_deliveries_keep_filing_under_the_payment_ids(): void
    {
        config(['wlcm.storage.logs' => 'database']);

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();

        $row = WlcmLog::sole();
        $this->assertSame('ORD-1', $row->external_id);
        $this->assertSame('1254', $row->order_id);
        $this->assertSame('7788', $row->payment_id);
    }

    public function test_true_resolves_the_database_driver(): void
    {
        config(['wlcm.storage.logs' => true]);

        $this->assertInstanceOf(DatabaseLogStore::class, $this->app->make(LogStore::class));
    }

    public function test_config_ships_both_stores_off(): void
    {
        $env = $_ENV['WLCM_LOGS'] ?? null;
        $server = $_SERVER['WLCM_LOGS'] ?? null;
        $putenv = getenv('WLCM_LOGS');
        unset($_ENV['WLCM_LOGS'], $_SERVER['WLCM_LOGS']);
        putenv('WLCM_LOGS');

        try {
            $config = require dirname(__DIR__, 2).'/config/wlcm.php';
        } finally {
            if ($env !== null) {
                $_ENV['WLCM_LOGS'] = $env;
            }

            if ($server !== null) {
                $_SERVER['WLCM_LOGS'] = $server;
            }

            if ($putenv !== false) {
                putenv("WLCM_LOGS={$putenv}");
            }
        }

        $this->assertIsArray($config);
        $this->assertIsArray($config['storage']);
        $this->assertFalse($config['storage']['logs']);
        $this->assertFalse($config['storage']['transactions']);
    }

    public function test_database_string_resolves_the_database_driver(): void
    {
        config(['wlcm.storage.logs' => 'database']);
        $this->fakeCheckout();

        $this->checkout();

        $this->assertSame('out', WlcmLog::sole()->direction);
    }

    public function test_false_resolves_the_database_driver_and_writes_nothing(): void
    {
        config(['wlcm.storage.logs' => false]);
        $this->fakeCheckout();

        $this->assertInstanceOf(DatabaseLogStore::class, $this->app->make(LogStore::class));

        $this->checkout();

        $this->assertSame(0, WlcmLog::query()->count());
    }

    public function test_env_one_writes_to_the_database(): void
    {
        config(['wlcm.storage.logs' => '1']);
        $this->fakeCheckout();

        $this->checkout();

        $this->assertSame('out', WlcmLog::sole()->direction);
    }

    public function test_env_zero_records_nothing_in_any_driver(): void
    {
        $path = $this->path();
        config(['wlcm.storage.logs' => '0', 'wlcm.storage.logs_path' => $path]);
        Exceptions::fake();
        $this->fakeCheckout();

        $logged = $this->captureLogContexts(function (): void {
            $this->checkout();
            (new DatabaseLogStore)->out(...$this->outArgs());
            (new FileLogStore)->out(...$this->outArgs());
            (new LogChannelLogStore)->out(...$this->outArgs());
        });

        $this->assertSame(0, WlcmLog::query()->count());
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $logged);
        $this->assertCount(0, $this->records('wlcm_default'));
        Exceptions::assertNothingReported();
    }

    public function test_file_driver_resolves_the_file_store(): void
    {
        $this->useFileDriver();

        $this->assertInstanceOf(FileLogStore::class, $this->app->make(LogStore::class));
    }

    public function test_log_driver_resolves_the_log_channel_store(): void
    {
        config(['wlcm.storage.logs' => 'log']);

        $this->assertInstanceOf(LogChannelLogStore::class, $this->app->make(LogStore::class));
    }

    public function test_unknown_driver_throws_at_resolution(): void
    {
        config(['wlcm.storage.logs' => 'syslog']);

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.storage.logs');

        $this->app->make(LogStore::class);
    }

    public function test_unknown_driver_fails_the_payment_call_closed(): void
    {
        config(['wlcm.storage.logs' => 'syslog']);
        $this->fakeCheckout();

        $this->expectException(MissingConfigurationException::class);

        $this->checkout();
    }

    public function test_file_driver_appends_one_json_line_per_entry(): void
    {
        $path = $this->useFileDriver();
        $this->fakeCheckout();

        $this->checkout();
        $this->checkout();

        $lines = $this->lines($path);
        $this->assertCount(2, $lines);
        $this->assertSame('out', $lines[0]['direction']);
        $this->assertSame('main', $lines[0]['connection']);
        $this->assertSame('partner', $lines[0]['api']);
        $this->assertSame('POST', $lines[0]['method']);
        $this->assertSame('/api/v1/integrations/checkout', $lines[0]['path']);
        $this->assertSame(201, $lines[0]['status_code']);
        $this->assertSame('ok', $lines[0]['result']);
        $this->assertSame('1254', $lines[0]['order_id']);
        $this->assertIsInt($lines[0]['duration_ms']);
        $this->assertIsString($lines[0]['created_at']);
        $this->assertSame(0, WlcmLog::query()->count());
    }

    public function test_file_driver_creates_the_missing_directory(): void
    {
        $path = $this->useFileDriver($this->dir().'/nested/deeper/audit.jsonl');
        $this->fakeCheckout();

        $this->checkout();

        $this->assertFileExists($path);
        $this->assertCount(1, $this->lines($path));
    }

    public function test_file_driver_defaults_to_the_storage_path(): void
    {
        config(['wlcm.storage.logs' => 'file', 'wlcm.storage.logs_path' => null]);
        $this->fakeCheckout();

        $this->checkout();

        $this->assertFileExists(storage_path('logs/wlcm.jsonl'));
    }

    public function test_file_driver_trims_a_padded_path(): void
    {
        $path = $this->path();
        $this->useFileDriver("  {$path}  ");
        $this->fakeCheckout();

        $this->checkout();

        $this->assertFileExists($path);
    }

    public function test_file_driver_never_writes_a_bare_pan(): void
    {
        $path = $this->useFileDriver();

        $this->cardCheckout();

        $contents = (string) file_get_contents($path);
        $this->assertStringNotContainsString('9860991234569999', $contents);
        $this->assertStringContainsString('986099******9999', $contents);
        $requestBody = $this->lines($path)[0]['request_body'];
        $this->assertIsArray($requestBody);
        $this->assertSame('***', $requestBody['expire_date']);
    }

    public function test_file_driver_records_webhook_deliveries(): void
    {
        $path = $this->useFileDriver();

        $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();

        $entry = $this->lines($path)[0];
        $this->assertSame('webhook', $entry['direction']);
        $this->assertSame('ok', $entry['result']);
        $this->assertSame('7788', $entry['payment_id']);
        $this->assertIsArray($entry['request_body']);
        $this->assertSame('ORD-1', $entry['request_body']['external_id']);
    }

    public function test_file_driver_reports_an_unwritable_path_without_breaking_the_call(): void
    {
        mkdir($this->dir(), 0777, true);
        file_put_contents($this->dir().'/blocker', 'x');
        $this->useFileDriver($this->dir().'/blocker/wlcm.jsonl');
        Exceptions::fake();
        $this->fakeCheckout();

        $result = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $this->assertSame('https://c.test/x', $result->checkoutUrl);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_log_driver_sends_the_entry_to_the_default_channel(): void
    {
        config(['wlcm.storage.logs' => 'log']);
        $this->fakeCheckout();

        $logged = $this->captureLogContexts(fn () => $this->checkout());

        $this->assertCount(1, $logged);
        $this->assertSame('info', $logged[0]['level']);
        $this->assertSame('wlcm', $logged[0]['message']);
        $this->assertSame('out', $logged[0]['context']['direction']);
        $this->assertSame(201, $logged[0]['context']['status_code']);
        $this->assertCount(1, $this->records('wlcm_default'));
        $this->assertSame(0, WlcmLog::query()->count());
    }

    public function test_log_driver_uses_the_configured_channel(): void
    {
        config(['wlcm.storage.logs' => 'log', 'wlcm.storage.logs_channel' => 'wlcm_capture']);
        $this->fakeCheckout();

        $this->checkout();

        $this->assertCount(1, $this->records('wlcm_capture'));
        $this->assertSame('out', $this->records('wlcm_capture')[0]->context['direction']);
        $this->assertCount(0, $this->records('wlcm_default'));
    }

    public function test_log_driver_trims_a_padded_channel(): void
    {
        config(['wlcm.storage.logs' => 'log', 'wlcm.storage.logs_channel' => '  wlcm_capture  ']);
        Exceptions::fake();
        $this->fakeCheckout();

        $this->checkout();

        $this->assertCount(1, $this->records('wlcm_capture'));
        Exceptions::assertNothingReported();
    }

    public function test_blank_channel_falls_back_to_the_default_channel(): void
    {
        config(['wlcm.storage.logs' => 'log', 'wlcm.storage.logs_channel' => '   ']);
        Exceptions::fake();
        $this->fakeCheckout();

        $this->checkout();

        $this->assertCount(1, $this->records('wlcm_default'));
        $this->assertCount(0, $this->records('wlcm_capture'));
        Exceptions::assertNothingReported();
    }

    public function test_a_ghost_channel_falls_back_to_the_emergency_logger(): void
    {
        config(['wlcm.storage.logs' => 'log', 'wlcm.storage.logs_channel' => 'wlcm_ghost']);
        Exceptions::fake();
        $this->fakeCheckout();

        $result = Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');

        $this->assertSame('https://c.test/x', $result->checkoutUrl);
        $this->assertFileExists($this->emergencyLog());

        $emergency = (string) file_get_contents($this->emergencyLog());
        $this->assertStringContainsString('Log [wlcm_ghost] is not defined', $emergency);
        $this->assertStringContainsString('Using emergency logger', $emergency);
        $this->assertStringContainsString('laravel.INFO: wlcm', $emergency);
        $this->assertStringContainsString('"direction":"out"', $emergency);
        $this->assertCount(0, $this->records('wlcm_default'));
        Exceptions::assertNothingReported();
    }

    public function test_log_driver_never_logs_a_bare_pan(): void
    {
        config(['wlcm.storage.logs' => 'log']);

        $logged = $this->captureLogContexts(fn () => $this->cardCheckout());

        $encoded = (string) json_encode($logged[0]['context']);
        $this->assertStringNotContainsString('9860991234569999', $encoded);
        $this->assertStringContainsString('986099******9999', $encoded);
    }

    public function test_log_driver_records_webhook_deliveries(): void
    {
        config(['wlcm.storage.logs' => 'log']);

        $logged = $this->captureLogContexts(function (): void {
            $this->postJson('/wlcm/webhook', $this->webhookData())->assertOk();
        });

        $this->assertCount(1, $logged);
        $this->assertSame('webhook', $logged[0]['context']['direction']);
        $this->assertSame('7788', $logged[0]['context']['payment_id']);
    }

    public function test_database_driver_drops_a_scalar_response_body(): void
    {
        config(['wlcm.storage.logs' => 'database']);

        $this->scalarBodyCheckout();

        $row = WlcmLog::sole();
        $this->assertNull($row->response_body);
        $this->assertSame('error', $row->result);
        $this->assertStringNotContainsString(self::SCALAR_BODY, (string) json_encode($row->getAttributes()));
    }

    public function test_file_driver_drops_a_scalar_response_body(): void
    {
        $path = $this->useFileDriver();

        $this->scalarBodyCheckout();

        $this->assertNull($this->lines($path)[0]['response_body']);
        $this->assertStringNotContainsString(self::SCALAR_BODY, (string) file_get_contents($path));
    }

    public function test_log_driver_drops_a_scalar_response_body(): void
    {
        config(['wlcm.storage.logs' => 'log']);

        $logged = $this->captureLogContexts(fn () => $this->scalarBodyCheckout());

        $this->assertCount(1, $logged);
        $this->assertNull($logged[0]['context']['response_body']);
        $this->assertStringNotContainsString(self::SCALAR_BODY, (string) json_encode($logged[0]['context']));
        $this->assertStringNotContainsString(
            self::SCALAR_BODY,
            (string) json_encode($this->records('wlcm_default')[0]->context),
        );
    }

    public function test_drivers_agree_on_the_out_entry_shape(): void
    {
        $this->freezeTime();
        $args = [
            'main', 'partner', 'POST', 'https://api.wlcm.test/api/v1/checkout',
            ['amount' => 1200], ['order_id' => 1254], 201, 12,
            ['external_id' => 'ORD-9', 'order_id' => '1254', 'payment_id' => '7788', 'transaction_id' => 'TX-1'],
            'ok',
        ];

        config(['wlcm.storage.logs' => 'database']);
        (new DatabaseLogStore)->out(...$args);

        $path = $this->useFileDriver();
        (new FileLogStore)->out(...$args);

        config(['wlcm.storage.logs' => 'log']);
        $logged = $this->captureLogContexts(function () use ($args): void {
            (new LogChannelLogStore)->out(...$args);
        });

        $row = array_filter(WlcmLog::sole()->getAttributes(), static fn (mixed $v): bool => $v !== null);
        unset($row['id']);

        $this->assertSame($this->sortedKeys($row), $this->sortedKeys($this->lines($path)[0]));
        $this->assertSame($this->lines($path)[0], $logged[0]['context']);
    }

    public function test_drivers_agree_on_the_webhook_entry_shape(): void
    {
        $this->freezeTime();
        $payload = ['external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788'];

        config(['wlcm.storage.logs' => 'database']);
        (new DatabaseLogStore)->webhook('main', $payload, 'ok');

        $path = $this->useFileDriver();
        (new FileLogStore)->webhook('main', $payload, 'ok');

        config(['wlcm.storage.logs' => 'log']);
        $logged = $this->captureLogContexts(function () use ($payload): void {
            (new LogChannelLogStore)->webhook('main', $payload, 'ok');
        });

        $row = array_filter(WlcmLog::sole()->getAttributes(), static fn (mixed $v): bool => $v !== null);
        unset($row['id']);

        $this->assertSame($this->sortedKeys($row), $this->sortedKeys($this->lines($path)[0]));
        $this->assertSame($this->lines($path)[0], $logged[0]['context']);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return list<string>
     */
    private function sortedKeys(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }

    /**
     * TRADE RECORDED, not an oversight. This used to assert that a driver instance stays
     * silent whenever a DIFFERENT driver is configured — and that self-disqualification
     * is precisely what discarded the whole audit trail on a runtime driver flip. A
     * driver cannot tell "I was resolved and the config moved under me" from "I am not
     * the chosen one"; the two readings are the same condition, so one had to go.
     *
     * What is kept: silence is governed by the on/off toggle, which is the case that
     * actually reaches production, since the container resolves exactly one driver.
     * What is given up: hand-constructing a second driver alongside the resolved one now
     * writes through both. Nothing in the package does that.
     */
    public function test_a_driver_writes_on_the_toggle_not_on_driver_identity(): void
    {
        $this->useFileDriver();
        $this->fakeCheckout();

        // Another driver is selected, but logging is ON: the instance you hold writes.
        (new DatabaseLogStore)->out('main', 'partner', 'POST', 'https://api.wlcm.test/x', null, null, 200, 1);
        $this->assertSame(1, WlcmLog::query()->count());

        config(['wlcm.storage.logs' => false]);
        (new DatabaseLogStore)->out('main', 'partner', 'POST', 'https://api.wlcm.test/x', null, null, 200, 1);
        $this->assertSame(1, WlcmLog::query()->count(), 'a disabled sink still wrote');
    }

    public function test_runtime_toggle_off_and_on_still_works(): void
    {
        $path = $this->useFileDriver();
        $this->fakeCheckout();
        $this->app->make(LogStore::class);

        config(['wlcm.storage.logs' => false]);
        $this->checkout();
        $this->assertFileDoesNotExist($path);

        config(['wlcm.storage.logs' => 'file']);
        $this->checkout();
        $this->assertCount(1, $this->lines($path));
    }

    /**
     * A driver switch after resolution still needs a rebind to take EFFECT — but the
     * resolved driver keeps writing, it does not go silent.
     *
     * This test previously asserted the opposite, that nothing was written anywhere. That
     * was pinning the defect: each driver asked "am I the configured driver" and
     * disqualified itself, so a flip in a warm process discarded the whole audit trail
     * with no error. The assertion is inverted here on purpose.
     */
    public function test_a_driver_switch_after_resolution_keeps_the_resolved_driver(): void
    {
        $path = $this->useFileDriver();
        $this->fakeCheckout();
        $this->app->make(LogStore::class);

        config(['wlcm.storage.logs' => 'log']);
        $logged = $this->captureLogContexts(fn () => $this->checkout());

        // The resolved file driver still writes...
        $this->assertFileExists($path);
        // ...and the newly named channel driver is still not used, which is the half of
        // the old claim that was true.
        $this->assertSame([], $logged);
    }

    /**
     * The appended `failed` verdict exists because the file and log-channel drivers are
     * append-only and cannot revise an entry. That argument is only worth anything if the
     * channel driver actually receives both records.
     */
    public function test_a_failed_dispatch_records_both_verdicts_on_the_log_channel(): void
    {
        config([
            'wlcm.storage.logs' => 'log',
            'wlcm.storage.transactions' => true,
            'wlcm.webhook.handler' => ThrowingWebhookHandler::class,
        ]);

        $results = [];
        Log::listen(function (MessageLogged $message) use (&$results): void {
            $context = $message->context;

            if (($context['direction'] ?? null) === 'webhook') {
                $results[] = $context['result'] ?? null;
            }
        });

        $data = [
            'external_id' => 'ORD-1', 'order_id' => '1254', 'payment_id' => '7788',
            'amount' => '1200.00', 'state' => '2', 'provider' => 'payme',
            'timestamp' => '2026-07-24 12:00:00',
        ];
        $data['signature'] = hash_hmac('sha256', '1254:7788:2:2026-07-24 12:00:00', 'whsec_test');

        $this->withoutExceptionHandling();

        try {
            $this->postJson('/wlcm/webhook', $data);
            $this->fail('expected the handler throw to surface');
        } catch (RuntimeException) {
            // expected
        }

        $this->withExceptionHandling();

        $this->assertSame(['ok', 'failed'], $results, 'the channel driver did not receive both verdicts');
    }
}
