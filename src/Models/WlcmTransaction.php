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
        $pruneDays = config('wlcm.storage.prune_days', 90);

        if (! is_numeric($pruneDays)) {
            return self::query()->whereRaw('1 = 0');
        }

        $days = max(1, (int) $pruneDays);

        return self::query()
            ->whereNotNull('processed_at')
            ->where('created_at', '<', now()->subDays($days));
    }
}
