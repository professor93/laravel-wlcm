<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Uzbek\Wlcm\Models\WlcmTransaction;
use Uzbek\Wlcm\Partner\Data\Order;
use Uzbek\Wlcm\Storage\TransactionRecorder;
use Uzbek\Wlcm\Tests\TestCase;

final class OrderRecordingTest extends TestCase
{
    use RefreshDatabase;

    // Every feature test in this suite loads the package migrations itself; testbench
    // does not pick them up from the provider.
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

    public function test_it_writes_one_row_with_no_provider_and_no_integer_state(): void
    {
        $order = new Order(orderId: 642, externalId: 'ORD-7', amountTiyin: 100000, status: 'created');

        $this->recorder()->recordOrder('main', ['amount' => 100000, 'external_id' => 'ORD-7'], $order);

        $row = WlcmTransaction::query()->sole();
        // getAttribute, not ->connection: the column name collides with Eloquent's own
        // protected $connection, so property access only reaches the column by falling
        // through to __get(). Same reason the recorder reads its claim columns this way.
        $this->assertSame('main', $row->getAttribute('connection'));
        $this->assertSame('partner', $row->getAttribute('api'));
        $this->assertSame('ORD-7', $row->external_id);
        $this->assertSame('642', $row->order_id);
        $this->assertSame(100000, $row->amount_tiyin);
        $this->assertNull($row->getAttribute('provider'));
        $this->assertNull($row->state);
        $this->assertSame('created', $row->getAttribute('state_text'));
        $this->assertNull($row->payment_id);
        $this->assertNull($row->delivery_key);
        $this->assertNull($row->processed_at);
        $this->assertNull($row->meta);
    }

    public function test_the_scrub_invariant_holds_in_both_directions(): void
    {
        // 4111111111111111 is Luhn-valid, so a blanket scrub would eat the order id too.
        $order = new Order(
            orderId: 4111111111111111,
            externalId: 'card 4111 1111 1111 1111 on file',
            amountTiyin: 100000,
            status: 'created',
        );

        $this->recorder()->recordOrder('main', ['amount' => 100000], $order);

        $row = WlcmTransaction::query()->sole();
        // Merchant free-text: masked.
        $this->assertStringNotContainsString('4111 1111 1111 1111', (string) $row->external_id);
        // Server-minted: raw, because scrubbing it would collide the dedupe key.
        $this->assertSame('4111111111111111', $row->order_id);
    }

    public function test_it_writes_nothing_when_the_store_is_disabled(): void
    {
        config()->set('wlcm.storage.transactions', false);

        $this->recorder()->recordOrder('main', ['amount' => 1], new Order(1, null, 1, 'created'));

        $this->assertSame(0, WlcmTransaction::query()->count());
    }

    public function test_a_null_response_amount_falls_back_to_the_requested_one(): void
    {
        // The live API always echoes amount, but the DTO parses defensively; the amount
        // the merchant sent is authoritative when the echo is missing.
        $order = new Order(orderId: 9, externalId: null, amountTiyin: null, status: 'created');

        $this->recorder()->recordOrder('main', ['amount' => 250000], $order);

        $this->assertSame(250000, WlcmTransaction::query()->sole()->amount_tiyin);
    }

    public function test_an_over_long_external_id_is_cut_rather_than_losing_the_row(): void
    {
        $order = new Order(orderId: 9, externalId: str_repeat('A', 200), amountTiyin: 1, status: 'created');

        $this->recorder()->recordOrder('main', ['amount' => 1], $order);

        $row = WlcmTransaction::query()->sole();
        $this->assertSame(100, strlen((string) $row->external_id));
    }

    public function test_a_broken_sink_does_not_break_order_creation(): void
    {
        // The audit trail is never allowed to take down a money path. With the table
        // gone the insert throws, report() swallows it, and the caller proceeds.
        Schema::drop('wlcm_transactions');

        $this->recorder()->recordOrder('main', ['amount' => 1], new Order(1, null, 1, 'created'));

        $this->addToAssertionCount(1);
    }
}
