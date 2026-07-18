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

    public const ROLE_MEMBER = 'member';
    public const ROLE_GUEST  = 'guest';

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

    public function isGuest(): bool
    {
        return $this->role === self::ROLE_GUEST;
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
