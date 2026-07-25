<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string|null $external_id
 * @property string|null $payment_id
 * @property int $amount_tiyin
 * @property int|null $state
 * @property Carbon|null $processed_at
 */
final class WlcmTransaction extends Model
{
    use MassPrunable;

    protected $table = 'wlcm_transactions';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'amount_tiyin' => 'integer',
        'state' => 'integer',
        'processed_at' => 'datetime',
    ];

    /** @return Builder<WlcmTransaction> */
    public function prunable(): Builder
    {
        $processedBefore = self::cutoff(config('wlcm.storage.prune_days', 90));
        $abandonedBefore = self::cutoff(config('wlcm.storage.stale_days'));

        $query = self::query()->whereRaw('1 = 0');

        if ($processedBefore instanceof Carbon) {
            $query->orWhere(fn (Builder $window) => $window
                ->whereNotNull('processed_at')
                ->where('created_at', '<', $processedBefore));
        }

        if ($abandonedBefore instanceof Carbon) {
            // Claimed row (payment_id/delivery_key set) = crashed dispatch. Never reap.
            $query->orWhere(fn (Builder $window) => $window
                ->whereNull('processed_at')
                ->whereNull('payment_id')
                ->whereNull('delivery_key')
                ->where('created_at', '<', $abandonedBefore));
        }

        return $query;
    }

    private static function cutoff(mixed $days): ?Carbon
    {
        return is_numeric($days) ? now()->subDays(max(1, (int) $days)) : null;
    }
}
