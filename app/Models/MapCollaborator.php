<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MapCollaborator extends Model
{
    use HasUuids;

    protected $table = 'map_collaborators';

    protected $fillable = [
        'map_id',
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

    /**
     * Get the map this collaboration is for
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * Get the user being added as collaborator
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who invited this collaborator
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Scope: Only accepted collaborators (not pending invitations)
     */
    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    /**
     * Scope: Only pending invitations
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Active collaborators (accepted + invitation token null)
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'accepted');
    }

    /**
     * Check if this collaboration is accepted
     */
    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    /**
     * Check if this is a pending invitation
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the given user is the map owner (implicitly)
     */
    public function isOwner(): bool
    {
        return (int) $this->map->owner_id === auth()->id();
    }

    /**
     * Check if current user is a collaborator (not owner)
     */
    public function isCollaborator(): bool
    {
        return $this->user_id === auth()->id() && $this->isAccepted();
    }

    /**
     * Accept an invitation
     */
    public function accept(): void
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }
}
