<?php

declare(strict_types=1);

namespace Uzbek\Wlcm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int|null $state
 * @property Carbon|null $processed_at
 */
final class WlcmTransaction extends Model
{
    protected $table = 'wlcm_transactions';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'amount_tiyin' => 'integer',
        'state' => 'integer',
        'processed_at' => 'datetime',
    ];
}
