<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MissionCollaborator extends Model
{
    use HasUuids;

    protected $table = 'mission_collaborators';

    protected $fillable = [
        'mission_id',
        'user_id',
        'added_by',
        'role',
        'status',
        'invitation_token',
        'invited_at',
        'accepted_at',
    ];

    protected $casts = [
        'status' => 'string',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function accept(): void
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }
}
