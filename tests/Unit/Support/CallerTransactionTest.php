<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Tests\Unit\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Uzbek\Wlcm\Exceptions\MissingConfigurationException;
use Uzbek\Wlcm\Support\CallerTransaction;
use Uzbek\Wlcm\Tests\TestCase;

final class CallerTransactionTest extends TestCase
{
    /**
     * The rule has exactly three boolean inputs, so the fixture set is their whole
     * cross-product rather than the cases anyone thought of. A hand-picked list of
     * "every way this can happen" has been wrong twice on this package.
     *
     * @return iterable<string, array{bool, bool, bool, bool}>
     */
    public static function everyInput(): iterable
    {
        foreach ([false, true] as $store) {
            foreach ([false, true] as $allow) {
                foreach ([false, true] as $open) {
                    $name = ($store ? 'store-on' : 'store-off')
                        .($allow ? ' allow-on' : ' allow-off')
                        .($open ? ' in-transaction' : ' no-transaction');

                    // Refuse only when all three line up: a row we would write, no
                    // opt-out, and a caller transaction to lose it in.
                    yield $name => [$store, $allow, $open, $store && ! $allow && $open];
                }
            }
        }
    }

    #[DataProvider('everyInput')]
    public function test_every_combination_of_the_three_inputs(bool $store, bool $allow, bool $open, bool $refused): void
    {
        config()->set('wlcm.storage.transactions', $store);
        config()->set('wlcm.allow_caller_transaction', $allow);

        $check = fn (): bool => CallerTransaction::open(app(Repository::class));

        $this->assertSame($refused, $open ? DB::transaction($check) : $check());
    }

    // Nesting is still a caller transaction. Savepoints roll back with the outer one.
    public function test_a_nested_transaction_still_counts(): void
    {
        config()->set('wlcm.storage.transactions', true);
        config()->set('wlcm.allow_caller_transaction', false);

        $this->assertTrue(DB::transaction(fn (): bool => DB::transaction(
            fn (): bool => CallerTransaction::open(app(Repository::class)),
        )));
    }

    // Same policy as every other toggle: a typo is a config error, not an off switch.
    public function test_an_unreadable_opt_out_throws(): void
    {
        config()->set('wlcm.storage.transactions', true);
        config()->set('wlcm.allow_caller_transaction', 'off');

        $this->expectException(MissingConfigurationException::class);
        $this->expectExceptionMessage('wlcm.allow_caller_transaction');

        DB::transaction(fn (): bool => CallerTransaction::open(app(Repository::class)));
    }

    /**
     * DO NOT DELETE. The store toggle is read LAST on purpose, so an app that keeps no
     * transaction rows never resolves a database connection here at all — the package
     * supports being used as a pure API client with no database configured, and reaching
     * for transactionLevel() first would break every checkout in one.
     */
    public function test_the_store_toggle_is_read_before_any_connection(): void
    {
        config()->set('wlcm.storage.transactions', false);
        config()->set('wlcm.allow_caller_transaction', false);
        config()->set('database.default', 'no-such-connection');

        $this->assertFalse(CallerTransaction::open(app(Repository::class)));
    }
}
