<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    use HasUuids;

    protected $table = 'team_members';

    protected $fillable = [
        'team_id',
        'email',
        'user_id',
        'added_by',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
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
