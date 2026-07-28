<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Uzbek\Wlcm\Connection;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Facades\Wlcm;
use Uzbek\Wlcm\Tests\TestCase;
use Uzbek\Wlcm\WlcmManager;

final class ManagerTest extends TestCase
{
    public function test_default_connection_resolves(): void
    {
        $this->assertInstanceOf(Connection::class, Wlcm::connection());
    }

    public function test_named_connection_caches(): void
    {
        $this->assertSame(Wlcm::connection('main'), Wlcm::connection('main'));
    }

    /**
     * The facade and the container hand back the same singleton manager; the tests reach
     * for it directly because Facades\Wlcm still has no @method line for these two.
     */
    private function manager(): WlcmManager
    {
        return app(WlcmManager::class);
    }

    private function fakeCheckout(): void
    {
        Http::fake(['*' => Http::response([
            'order_id' => 1254, 'external_id' => 'ORD-9', 'state' => 0,
            'checkout_url' => 'https://c.test/x', 'message' => null,
        ], 201)]);
    }

    private function checkout(): void
    {
        Wlcm::partner()->checkout()->create(amountTiyin: 120000, returnUrl: 'https://x.test', externalId: 'ORD-9');
    }

    /**
     * Connections are memoised per name and PartnerClient copies its config in the
     * constructor, so without a seam a per-tenant base_url or key rotation is invisible
     * for the whole life of an octane worker. The host owns the tenant boundary, so the
     * host gets the switch.
     */
    public function test_forget_rebuilds_the_named_connection_from_current_config(): void
    {
        $first = Wlcm::connection('main');
        config()->set('wlcm.connections.main.partner.base_url', 'https://api.other.test/api/v1');

        $this->assertSame($first, Wlcm::connection('main'), 'memoised until asked otherwise');

        $this->manager()->forget('main');

        $this->assertNotSame($first, Wlcm::connection('main'));
    }

    public function test_forget_sends_the_next_call_to_the_new_base_url(): void
    {
        $this->fakeCheckout();
        $this->checkout();

        config()->set('wlcm.connections.main.partner.base_url', 'https://api.other.test/api/v1');
        $this->manager()->forget('main');
        $this->checkout();

        Http::assertSent(static fn (Request $r): bool => str_starts_with($r->url(), 'https://api.other.test/'));
        Http::assertSentCount(2);
    }

    public function test_forget_picks_up_a_rotated_api_key(): void
    {
        $this->fakeCheckout();
        $this->checkout();

        config()->set('wlcm.connections.main.partner.api_key', 'pk_rotated');
        $this->manager()->forget('main');
        $this->checkout();

        Http::assertSent(static fn (Request $r): bool => $r->header('X-API-Key') === ['pk_rotated']);
    }

    public function test_flush_drops_every_memoised_connection(): void
    {
        config()->set('wlcm.connections.second', config('wlcm.connections.main'));

        $main = Wlcm::connection('main');
        $second = Wlcm::connection('second');

        $this->manager()->flush();

        $this->assertNotSame($main, Wlcm::connection('main'));
        $this->assertNotSame($second, Wlcm::connection('second'));
    }

    public function test_forgetting_a_name_leaves_the_others_memoised(): void
    {
        config()->set('wlcm.connections.second', config('wlcm.connections.main'));

        $main = Wlcm::connection('main');
        $second = Wlcm::connection('second');

        $this->manager()->forget('main');

        $this->assertNotSame($main, Wlcm::connection('main'));
        $this->assertSame($second, Wlcm::connection('second'));
    }

    public function test_forgetting_an_unresolved_name_is_harmless(): void
    {
        $this->manager()->forget('never-resolved');

        $this->assertInstanceOf(Connection::class, Wlcm::connection('main'));
    }

    public function test_unknown_connection_throws(): void
    {
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.connections.nope');
        Wlcm::connection('nope');
    }

    public function test_null_leaf_config_throws_exact_key(): void
    {
        config()->set('wlcm.connections.main.partner.api_secret', null);
        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.connections.main.partner.api_secret');
        Wlcm::connection('main')->partner();
    }
}
