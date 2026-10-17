<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Eén rij = verbruik van één provider/kind in één UTC-uur, voor één
 * (gebruiker, netwerk, bron)-combinatie. Wordt alleen met atomaire increments
 * beschreven via App\Services\ApiUsageMeter::write().
 */
class ApiUsageHourly extends Model
{
    use MassPrunable;

    protected $table = 'api_usage_hourly';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'bucket'     => 'datetime',
        'user_id'    => 'integer',
        'requests'   => 'integer',
        'errors'     => 'integer',
        'cache_hits' => 'integer',
    ];

    /** Retentie: `model:prune` (routes/console.php) ruimt oudere uren op. */
    public function prunable(): Builder
    {
        $days = (int) config('api_usage.retention_days', 180);

        return static::where('bucket', '<', now('UTC')->subDays($days)->format('Y-m-d H:00:00'));
    }
}
