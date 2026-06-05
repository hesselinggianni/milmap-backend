<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasUuids;

    protected $table = 'teams';

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'color',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'team_id');
    }

    public function isOwnedBy(int $userId): bool
    {
        return (int) $this->owner_id === (int) $userId;
    }
}
