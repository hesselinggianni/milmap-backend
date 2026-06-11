<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mission extends Model
{
    use HasUuids;
    use SoftDeletes;

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

        // Prefer the eager-loaded collaborators collection when the caller has
        // already loaded it (e.g. the mission list) so we don't fire one query
        // per mission. When it isn't loaded the query path is byte-identical to
        // before, so every existing caller behaves exactly as it did.
        $collab = $this->relationLoaded('collaborators')
            ? $this->collaborators->first(
                fn ($c) => (int) $c->user_id === (int) $userId && $c->status === 'accepted'
            )
            : $this->collaborators()
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

    /**
     * Scope: missions a user participates in — ones they own, ones they were
     * invited to and accepted, and (while active) ones whose linked team they
     * belong to. Single source of truth for "missions you're in", shared by the
     * mission list and the chat list's mission group chats.
     */
    public function scopeParticipatedBy($query, int $userId)
    {
        $teamIds = TeamMember::where('user_id', $userId)->pluck('team_id');

        return $query->where(function ($q) use ($userId, $teamIds) {
            $q->where('owner_id', $userId)
                ->orWhereHas('collaborators', function ($c) use ($userId) {
                    $c->where('user_id', $userId)->where('status', 'accepted');
                })
                ->orWhere(function ($c) use ($teamIds) {
                    // Auto-viewer: active missions whose linked team includes the user.
                    $c->where('status', 'active')->whereIn('linked_team_id', $teamIds);
                });
        });
    }

    /**
     * Find-or-create this mission's single group ('channel') conversation, keep
     * its title in step with the mission name, and sync its participant set to
     * the current mission roster. Returns the conversation.
     *
     * This is the one place mission ↔ group-chat membership is reconciled, so
     * both the mission Comms tab and the chat list stay consistent.
     */
    public function syncGroupConversation(): Conversation
    {
        $title = $this->name ?: 'Missie';

        $conversation = Conversation::where('mission_id', $this->id)
            ->where('type', 'channel')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'type'       => 'channel',
                'name'       => $title,
                'mission_id' => $this->id,
                'created_by' => $this->owner_id,
            ]);
        } elseif ($conversation->name !== $title) {
            // Keep the group title in step with the mission name.
            $conversation->update(['name' => $title]);
        }

        // Keep the group roster aligned with mission membership — but only WRITE
        // when it actually differs. This method runs on plain GET loads (the chat
        // list and the mission Comms tab), so an unconditional sync() would issue
        // pivot writes on every read (write amplification + a REST/caching
        // hazard). Comparing first makes the steady state a single read.
        $desired = $this->participantUserIds();
        sort($desired);

        $current = $conversation->participants()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($current !== $desired) {
            $conversation->participants()->sync($desired);
        }

        return $conversation;
    }
}
