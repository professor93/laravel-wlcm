<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Partner\Data\Order;
use Uzbek\Wlcm\Partner\Data\OrderLink;
use Uzbek\Wlcm\Storage\TransactionRecorder;
use Uzbek\Wlcm\Tests\TestCase;

final class OrderLinkRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('wlcm.storage.transactions', true);
    }

    private function recorder(): TransactionRecorder
    {
        return app(TransactionRecorder::class);
    }

    private function seedOrder(string $connection = 'main', int $orderId = 661): void
    {
        $this->recorder()->recordOrder(
            $connection,
            ['amount' => 300000],
            new Order(orderId: $orderId, externalId: 'ORD-7', amountTiyin: 300000, status: 'created'),
        );
    }

    public function test_it_completes_the_row_the_order_left_behind(): void
    {
        $this->seedOrder();

        $this->recorder()->recordOrderLink('main', 'payme', new OrderLink(
            orderId: 661,
            externalId: 'ORD-7',
            checkoutUrl: 'https://sandbox.wlcm.uz/pay/661',
        ));

        $row = WlcmTransaction::query()->sole();
        $this->assertSame('payme', $row->getAttribute('provider'));
        $this->assertSame(['checkout_url' => 'https://sandbox.wlcm.uz/pay/661'], $row->meta);
        // Untouched by the link: the order's own facts still stand.
        $this->assertSame('661', $row->order_id);
        $this->assertSame(300000, $row->amount_tiyin);
        $this->assertSame('created', $row->getAttribute('state_text'));
    }

    public function test_a_provider_switch_updates_in_place_rather_than_duplicating(): void
    {
        $this->seedOrder();

        $link = new OrderLink(661, 'ORD-7', 'https://sandbox.wlcm.uz/pay/661');
        $this->recorder()->recordOrderLink('main', 'mock_payme', $link);
        $this->recorder()->recordOrderLink('main', 'payme', $link);

        // Server semantics are last-call-wins, and the audit row must agree.
        $this->assertSame(1, WlcmTransaction::query()->count());
        $this->assertSame('payme', WlcmTransaction::query()->sole()->getAttribute('provider'));
    }

    public function test_it_matches_on_the_connection_too(): void
    {
        $this->seedOrder('main', 661);
        $this->seedOrder('other', 661);

        $this->recorder()->recordOrderLink('other', 'payme', new OrderLink(661, 'ORD-7', 'https://x.test/pay/661'));

        $this->assertNull(WlcmTransaction::query()->where('connection', 'main')->sole()->getAttribute('provider'));
        $this->assertSame('payme', WlcmTransaction::query()->where('connection', 'other')->sole()->getAttribute('provider'));
    }

    public function test_a_width_cut_connection_name_is_still_found(): void
    {
        // recordOrder stored the name cut to 64; a full-width lookup would miss the row
        // it just wrote. Both sides must go through the same fit.
        $long = str_repeat('c', 200);
        $this->seedOrder($long, 661);

        $this->recorder()->recordOrderLink($long, 'payme', new OrderLink(661, 'ORD-7', 'https://x.test/pay/661'));

        $this->assertSame('payme', WlcmTransaction::query()->sole()->getAttribute('provider'));
    }

    public function test_no_matching_order_row_is_a_silent_no_op(): void
    {
        // Storage was off during create(), or the order came from somewhere else. The
        // link is not a payment fact of its own, so there is nothing to insert.
        $this->recorder()->recordOrderLink('main', 'payme', new OrderLink(999, null, 'https://x.test/pay/999'));

        $this->assertSame(0, WlcmTransaction::query()->count());
    }

    public function test_it_writes_nothing_when_the_store_is_disabled(): void
    {
        $this->seedOrder();
        config()->set('wlcm.storage.transactions', false);

        $this->recorder()->recordOrderLink('main', 'payme', new OrderLink(661, 'ORD-7', 'https://x.test/pay/661'));

        $this->assertNull(WlcmTransaction::query()->sole()->getAttribute('provider'));
    }

    public function test_a_broken_sink_does_not_break_link_generation(): void
    {
        Schema::drop('wlcm_transactions');

        $this->recorder()->recordOrderLink('main', 'payme', new OrderLink(661, 'ORD-7', 'https://x.test/pay/661'));

        $this->addToAssertionCount(1);
    }
}
