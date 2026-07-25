<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $direction
 * @property array<array-key, mixed>|null $request_body
 * @property array<array-key, mixed>|null $response_body
 * @property int|null $status_code
 * @property string|null $external_id
 * @property string|null $order_id
 * @property string|null $payment_id
 * @property string|null $transaction_id
 * @property string $result
 * @property Carbon|null $created_at
 */
final class WlcmLog extends Model
{
    use MassPrunable;

    protected $table = 'wlcm_logs';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'request_body' => 'array',
        'response_body' => 'array',
        'status_code' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $log): void {
            $log->created_at ??= now();
        });
    }

    /** @return Builder<WlcmLog> */
    public function prunable(): Builder
    {
        $pruneDays = config('wlcm.storage.prune_days', 90);

        if (! is_numeric($pruneDays)) {
            return self::query()->whereRaw('1 = 0');
        }

        $days = max(1, (int) $pruneDays);

        return self::query()->where('created_at', '<', now()->subDays($days));
    }
}
