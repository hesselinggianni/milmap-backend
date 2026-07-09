<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Herbruikbare O-group template (fase-builder). De fase/subfase/veld-structuur
 * staat als vrij JSON in `definition`. Per-account, optioneel team-gedeeld.
 */
class OgroupTemplate extends Model
{
    use HasUuids;

    protected $table = 'ogroup_templates';

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'definition',
        'is_shared',
        'team_id',
    ];

    protected $casts = [
        'definition' => 'array',
        'is_shared'  => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
