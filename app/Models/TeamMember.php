<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    use HasUuids;

    protected $table = 'team_members';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';

    public const ROLE_MEMBER   = 'member';
    public const ROLE_GUEST    = 'guest';
    public const ROLE_TEAMLEAD = 'teamlead';

    protected $fillable = [
        'team_id',
        'email',
        'user_id',
        'added_by',
        'role',
        'permissions',
        'status',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * team_id's waarvan de gebruiker geaccepteerd lid is, als subquery. Eén
     * definitie voor "teamlid" bij missie- én kaarttoegang: pending = nog niet
     * gekozen voor het team (eigen abonnement) en telt dus nog niet mee.
     */
    public static function activeTeamIdsQuery(int $userId)
    {
        return static::query()->select('team_id')
            ->where('user_id', $userId)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function isGuest(): bool
    {
        return $this->role === self::ROLE_GUEST;
    }

    /** Teamlead: mag namens de owner teamleden beheren (toevoegen/rollen/rechten/verwijderen). */
    public function isTeamlead(): bool
    {
        return $this->role === self::ROLE_TEAMLEAD;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Display name for the member: the linked user's full name when available,
     * otherwise just the e-mail address.
     */
    public function displayName(): string
    {
        return $this->user?->full_name ?? $this->email;
    }
}
