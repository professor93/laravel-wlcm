<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Uzbek\Wlcm\Tests\TestCase;

/**
 * A merchant who ran `vendor:publish --tag=wlcm-config` owns a copy of this
 * package's config file. Their copy is loaded by LoadConfiguration before any
 * provider registers, and the provider then merges the packaged defaults under
 * it. Everything here is about what survives that merge.
 */
final class PublishedConfigMergeTest extends TestCase
{
    /**
     * LoadConfiguration is where a published config/wlcm.php lands, so this is
     * the only hook early enough to model one: defineEnvironment runs after the
     * provider has already merged.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        // A hand-edited copy taken before lease_seconds existed, with the
        // connection renamed and 'main' deleted.
        $app['config']->set('wlcm', [
            'default' => 'acme',
            'connections' => [
                'acme' => [
                    'partner' => [
                        'base_url' => 'https://acme.test/api/v1',
                        'api_key' => 'pk_acme',
                        'api_secret' => 'sk_acme',
                        'webhook_secret' => 'whsec_acme',
                    ],
                ],
            ],
            'webhook' => [
                'enabled' => true,
                'path' => 'hooks/custom',
                'handler' => null,
                'subscription_handler' => null,
                'tolerance_seconds' => null,
            ],
            'storage' => [
                'logs' => false,
            ],
        ]);
    }

    /**
     * Deliberately does not call parent: the suite's usual overrides write into
     * wlcm.* after the merge has happened, which would mask the thing under test.
     */
    protected function defineEnvironment($app): void {}

    public function test_packaged_defaults_survive_a_published_webhook_block(): void
    {
        $this->assertSame(60, config('wlcm.webhook.lease_seconds'));
    }

    public function test_packaged_defaults_survive_a_published_storage_block(): void
    {
        $storage = config('wlcm.storage');

        $this->assertIsArray($storage);
        $this->assertSame(
            ['logs', 'logs_channel', 'logs_path', 'transactions', 'prune_days', 'stale_days', 'file'],
            array_keys($storage),
        );
        $this->assertSame(90, $storage['prune_days']);
    }

    public function test_a_top_level_key_the_published_file_omits_comes_from_the_package(): void
    {
        $this->assertFalse(config('wlcm.allow_caller_transaction'));
    }

    public function test_published_values_still_beat_the_packaged_defaults(): void
    {
        $this->assertSame('acme', config('wlcm.default'));
        $this->assertSame('hooks/custom', config('wlcm.webhook.path'));
    }

    /** null is the only way to disable the timestamp window, so it must outrank the packaged 300. */
    public function test_a_published_null_is_an_override_not_an_absence(): void
    {
        $this->assertNull(config('wlcm.webhook.tolerance_seconds'));
    }

    /**
     * The cost of merging recursively: a published file can override any key but
     * can no longer delete one. 'main' comes back, and with it a routable
     * /{path}/main webhook URI whose credentials come from env.
     */
    public function test_a_published_file_can_no_longer_delete_a_packaged_connection(): void
    {
        $connections = config('wlcm.connections');

        $this->assertIsArray($connections);
        $this->assertSame(['main', 'acme'], array_keys($connections));
    }
}
