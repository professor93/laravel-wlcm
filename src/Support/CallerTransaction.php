<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Support;

use Illuminate\Contracts\Config\Repository;
use Uzbek\Wlcm\Models\WlcmTransaction;

/**
 * One rule, three call sites: is a wlcm_transactions row about to be written inside a
 * transaction the caller opened, without the caller having said they accept it.
 *
 * Deliberately a predicate rather than a thrower — each call site knows what it is
 * refusing and what the merchant loses by it, and those messages are not interchangeable.
 */
final class CallerTransaction
{
    public static function open(Repository $config): bool
    {
        // Store toggle FIRST, and not merely as an ordering preference. An app that keeps
        // no transaction rows may have no database configured at all, and reaching for a
        // connection ahead of this read would break every checkout in one. It is also the
        // honest condition: with no row to lose, a caller rollback loses nothing, and
        // refusing anyway would only teach merchants to set the opt-out for a reason that
        // does not apply — which then silently covers the real hazard later.
        $mode = ConfigToggle::transactionsMode($config->get('wlcm.storage.transactions', false));

        if ($mode === 'off') {
            return false;
        }

        // 'cache' guards too, for a mirrored hazard rather than the same one: a cache
        // claim does NOT roll back with the caller, so a rollback leaves the marker
        // standing and every redelivery is suppressed — the events fired, the merchant's
        // own writes vanished, and nothing will try again.
        if ($mode === 'cache') {
            return ! ConfigToggle::enabled($config->get('wlcm.allow_caller_transaction', false), 'wlcm.allow_caller_transaction')
                && (new WlcmTransaction)->getConnection()->transactionLevel() > 0;
        }

        if (ConfigToggle::enabled($config->get('wlcm.allow_caller_transaction', false), 'wlcm.allow_caller_transaction')) {
            return false;
        }

        // The connection the row would land on, asked of the model that owns it.
        return (new WlcmTransaction)->getConnection()->transactionLevel() > 0;
    }
}
