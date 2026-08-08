<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén foto bij een opgeslagen activiteit.
 *
 * @see \App\Http\Controllers\ActivityController
 */
class ActivityPhoto extends Model
{
    protected $fillable = [
        'activity_id',
        'url',
        'filename',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
