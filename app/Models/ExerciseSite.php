<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Terrein (Site) — vaste locatie voor de Oefening-modus: kaart + huisregels +
 * vaste rollen (site_members). Zie docs/oefening-modus.md.
 */
class ExerciseSite extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'exercise_sites';

    protected $fillable = [
        'owner_id',
        'map_id',
        'name',
        'description',
        'center_lat',
        'center_lng',
        'house_rules',
        'rules_version',
        'status',
    ];

    protected $casts = [
        'center_lat'    => 'float',
        'center_lng'    => 'float',
        'rules_version' => 'integer',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SiteMember::class, 'site_id');
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class, 'exercise_site_id');
    }

    public function isOwnedBy(int $userId): bool
    {
        return (int) $this->owner_id === (int) $userId;
    }

    /**
     * De terrein-rol van een gebruiker: de eigenaar is altijd commander; anders
     * de rol uit site_members; anders null (geen terrein-rol).
     */
    public function roleFor(int $userId): ?string
    {
        if ($this->isOwnedBy($userId)) {
            return 'commander';
        }

        $member = $this->relationLoaded('members')
            ? $this->members->firstWhere('user_id', $userId)
            : $this->members()->where('user_id', $userId)->first();

        return $member?->role;
    }
}
