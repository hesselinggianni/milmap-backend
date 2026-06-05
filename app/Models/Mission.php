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
        'linked_team_id',
        'name',
        'description',
        'status',
        'date',
        'time',
        'ogroup',
        'map',
        'logo',
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
     * The team whose members get automatic viewer access when this mission is active.
     */
    public function linkedTeam()
    {
        return $this->belongsTo(Team::class, 'linked_team_id');
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
     * - 'owner'  → the creator
     * - accepted collaborator role → invited & accepted user
     * - 'viewer' → member of the linked team when mission status is 'active'
     * - null     → no access
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

        if ($collab) {
            return $collab->role;
        }

        // Auto-viewer: member of the linked team while the mission is active
        if ($this->status === 'active' && $this->linked_team_id) {
            $isMember = TeamMember::where('team_id', $this->linked_team_id)
                ->where('user_id', $userId)
                ->exists();
            if ($isMember) {
                return 'viewer';
            }
        }

        return null;
    }

    /**
     * Does the user have any (owner or accepted collaborator) access?
     */
    public function hasAccess(int $userId): bool
    {
        return $this->roleFor($userId) !== null;
    }

    /**
     * Every user who participates in this mission and therefore belongs in the
     * mission group chat: the owner, all accepted collaborators, and — while the
     * mission is active — the members of the linked team. Returns unique ints.
     */
    public function participantUserIds(): array
    {
        $ids = [(int) $this->owner_id];

        $ids = array_merge(
            $ids,
            $this->collaborators()
                ->where('status', 'accepted')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        if ($this->status === 'active' && $this->linked_team_id) {
            $ids = array_merge(
                $ids,
                TeamMember::where('team_id', $this->linked_team_id)
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
            );
        }

        return array_values(array_unique(array_filter($ids)));
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
