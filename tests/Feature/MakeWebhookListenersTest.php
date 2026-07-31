<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Uzbek\Wlcm\Tests\TestCase;

final class MakeWebhookListenersTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/wlcm-listeners-'.bin2hex(random_bytes(6));
        $this->app->setBasePath($this->base);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->base);
        parent::tearDown();
    }

    private function dir(): string
    {
        return $this->base.'/app/Listeners/Wlcm';
    }

    public function test_it_scaffolds_every_event_by_default(): void
    {
        $this->artisan('wlcm:webhook:listeners')->assertExitCode(0);

        foreach ([
            'PaymentSucceededListener', 'PaymentCancelledListener',
            'SubscriptionActivatedListener', 'SubscriptionChargedListener',
            'SubscriptionChargeFailedListener', 'SubscriptionCancelledListener',
            'WebhookReceivedListener',
        ] as $class) {
            $this->assertFileExists($this->dir().'/'.$class.'.php');
        }
    }

    public function test_it_scaffolds_only_what_was_asked_for(): void
    {
        $this->artisan('wlcm:webhook:listeners', ['--events' => 'PaymentSucceeded'])->assertExitCode(0);

        $this->assertFileExists($this->dir().'/PaymentSucceededListener.php');
        $this->assertFileDoesNotExist($this->dir().'/PaymentCancelledListener.php');
    }

    public function test_the_generated_class_is_valid_php_with_the_right_type_hint(): void
    {
        $this->artisan('wlcm:webhook:listeners', ['--events' => 'PaymentSucceeded'])->assertExitCode(0);

        $source = (string) file_get_contents($this->dir().'/PaymentSucceededListener.php');

        $this->assertStringContainsString('namespace App\Listeners\Wlcm;', $source);
        $this->assertStringContainsString('use Uzbek\Wlcm\Events\PaymentSucceeded;', $source);
        // Laravel discovers listeners by this type-hint, so it is load-bearing.
        $this->assertStringContainsString('public function handle(PaymentSucceeded $event): void', $source);

        // Parses as PHP, not merely looks right.
        $this->assertSame(0, $this->lintExitCode($this->dir().'/PaymentSucceededListener.php'));
    }

    public function test_the_catch_all_listener_hints_the_union_payload(): void
    {
        $this->artisan('wlcm:webhook:listeners', ['--events' => 'WebhookReceived'])->assertExitCode(0);

        $source = (string) file_get_contents($this->dir().'/WebhookReceivedListener.php');

        // It fires for both families, so its payload really is a union.
        $this->assertStringContainsString('WebhookPayload|SubscriptionWebhookPayload', $source);
        $this->assertStringContainsString('use Uzbek\Wlcm\Webhooks\SubscriptionWebhookPayload;', $source);
        $this->assertSame(0, $this->lintExitCode($this->dir().'/WebhookReceivedListener.php'));
    }

    public function test_every_stub_carries_the_idempotency_caveat(): void
    {
        $this->artisan('wlcm:webhook:listeners')->assertExitCode(0);

        foreach ((new Filesystem)->files($this->dir()) as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $this->assertStringContainsString('MUST BE IDEMPOTENT', $source, $file->getFilename());
            $this->assertStringContainsString('at-least-once', $source, $file->getFilename());
        }
    }

    public function test_it_never_overwrites_without_force(): void
    {
        $this->artisan('wlcm:webhook:listeners', ['--events' => 'PaymentSucceeded'])->assertExitCode(0);
        file_put_contents($this->dir().'/PaymentSucceededListener.php', '<?php // my own work');

        $this->artisan('wlcm:webhook:listeners', ['--events' => 'PaymentSucceeded'])
            ->expectsOutputToContain('skipped')
            ->assertExitCode(0);

        $this->assertSame('<?php // my own work', file_get_contents($this->dir().'/PaymentSucceededListener.php'));
    }

    public function test_force_overwrites(): void
    {
        $this->artisan('wlcm:webhook:listeners', ['--events' => 'PaymentSucceeded'])->assertExitCode(0);
        file_put_contents($this->dir().'/PaymentSucceededListener.php', '<?php // my own work');

        $this->artisan('wlcm:webhook:listeners', ['--events' => 'PaymentSucceeded', '--force' => true])
            ->assertExitCode(0);

        $this->assertStringContainsString('MUST BE IDEMPOTENT', (string) file_get_contents($this->dir().'/PaymentSucceededListener.php'));
    }

    public function test_an_unknown_event_is_a_usage_error_and_writes_nothing(): void
    {
        // Generating nothing in silence is how someone waits for a listener that will
        // never fire.
        $this->artisan('wlcm:webhook:listeners', ['--events' => 'PaymentSucceeded,NoSuchEvent'])
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->dir().'/PaymentSucceededListener.php');
    }

    private function lintExitCode(string $path): int
    {
        // escapeshellarg, not escapeshellcmd: the latter mangles a binary path into
        // something the shell cannot find, which returns 127 and looks like a lint error.
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);

        return $code;
    }
}
