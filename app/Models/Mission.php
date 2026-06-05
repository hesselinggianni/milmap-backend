<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Mission extends Model
{
    use HasUuids;

    protected $table = 'missions';

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'status',
        'date',
        'time',
        'ogroup',
        'map',
    ];

    protected $casts = [
        'ogroup' => 'array',
        'map' => 'array',
        'date' => 'date',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    /**
     * The user who owns (created) this mission.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * All collaborators (invited users) on this mission.
     */
    public function collaborators()
    {
        return $this->hasMany(MissionCollaborator::class, 'mission_id');
    }

    /**
     * Is the given user the owner of this mission?
     */
    public function isOwnedBy(int $userId): bool
    {
        return (int) $this->owner_id === (int) $userId;
    }

    /**
     * The effective role of a user on this mission:
     * 'owner' for the creator, the collaborator role for accepted members,
     * or null when the user has no access.
     */
    public function roleFor(int $userId): ?string
    {
        if ($this->isOwnedBy($userId)) {
            return 'owner';
        }

        $collab = $this->collaborators()
            ->where('user_id', $userId)
            ->where('status', 'accepted')
            ->first();

        return $collab?->role;
    }

    /**
     * Does the user have any (owner or accepted collaborator) access?
     */
    public function hasAccess(int $userId): bool
    {
        return $this->roleFor($userId) !== null;
    }

    /**
     * Can the user edit this mission (owner, editor or admin)?
     */
    public function canEdit(int $userId): bool
    {
        return in_array($this->roleFor($userId), ['owner', 'editor', 'admin'], true);
    }

    /**
     * Can the user manage collaborators (owner or admin)?
     */
    public function canManage(int $userId): bool
    {
        return in_array($this->roleFor($userId), ['owner', 'admin'], true);
    }
}
