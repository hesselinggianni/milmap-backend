<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Eén rij = hoeveel fouten van één provider/kind in één UTC-uur om één reden
 * mislukten (zie de migratie voor de codes). Alleen met atomaire increments
 * beschreven via App\Services\ApiUsageMeter::writeReasons().
 */
class ApiUsageErrorReason extends Model
{
    use MassPrunable;

    protected $table = 'api_usage_error_reasons';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'bucket'  => 'datetime',
        'user_id' => 'integer',
        'count'   => 'integer',
    ];

    /** Zelfde retentie als api_usage_hourly. */
    public function prunable(): Builder
    {
        $days = (int) config('api_usage.retention_days', 180);

        return static::where('bucket', '<', now('UTC')->subDays($days)->format('Y-m-d H:00:00'));
    }
}
