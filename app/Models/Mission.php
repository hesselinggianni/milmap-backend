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
        'mission_number',
        'classification',
        'description',
        'status',
        'date',
        'time',
        'ogroup',
        'template_info',
        'map',
        'logo',
        'approved_by',
        'approved_at',
        'locked',
        'game_mode',
        'exercise_site_id',
    ];

    protected $casts = [
        'ogroup'        => 'array',
        'template_info' => 'array',
        'map'         => 'array',
        'date'        => 'date',
        'approved_at' => 'datetime',
        'locked'      => 'boolean',
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

    public function briefing()
    {
        return $this->hasOne(MissionBriefing::class, 'mission_id');
    }

    public function radioChannels()
    {
        return $this->hasMany(MissionRadioChannel::class, 'mission_id')->orderBy('sort_order');
    }

    public function risks()
    {
        return $this->hasMany(MissionRisk::class, 'mission_id')->orderBy('sort_order');
    }

    public function routeMaps()
    {
        return $this->hasMany(RouteMap::class, 'mission_id');
    }

    public function auditLog()
    {
        return $this->hasMany(MissionAuditLog::class, 'mission_id')->latest('created_at');
    }

    // ── Oefening-modus (exercise) ──────────────────────────────────────────

    /**
     * Is this mission an exercise (airsoft / force-on-force training)?
     */
    public function isExercise(): bool
    {
        return $this->game_mode === 'exercise';
    }

    /**
     * The terrein (site) this exercise is bound to.
     */
    public function exerciseSite()
    {
        return $this->belongsTo(ExerciseSite::class, 'exercise_site_id');
    }

    /**
     * The partijen (factions) players choose between in this exercise.
     */
    public function factions()
    {
        return $this->hasMany(ExerciseFaction::class, 'mission_id');
    }

    /**
     * The exercise participants (players/controllers/commanders in this game).
     */
    public function participants()
    {
        return $this->hasMany(ExerciseParticipant::class, 'mission_id');
    }

    /**
     * The doelen (objectives) of this exercise.
     */
    public function objectives()
    {
        return $this->hasMany(ExerciseObjective::class, 'mission_id');
    }

    /**
     * The ingediende acties (submissions) on this exercise's objectives.
     */
    public function submissions()
    {
        return $this->hasMany(ExerciseSubmission::class, 'mission_id');
    }

    /**
     * The effective exercise role of a user within this exercise:
     *   1. per-exercise override (exercise_participants.role_override), else
     *   2. the terrein-rol on the linked site (site owner = commander), else
     *   3. 'player' when the user participates at all, else
     *   4. null (no access).
     *
     * commander → beheert alles · controller → keurt acties goed/af · player → deelnemer.
     */
    public function exerciseRoleFor(int $userId): ?string
    {
        // Mission owner runs the show.
        if ($this->isOwnedBy($userId)) {
            return 'commander';
        }

        $participant = $this->relationLoaded('participants')
            ? $this->participants->firstWhere('user_id', $userId)
            : $this->participants()->where('user_id', $userId)->first();

        if ($participant && $participant->role_override) {
            return $participant->role_override;
        }

        // Inherit the terrein-rol (commander/controller) from the linked site.
        if ($this->exercise_site_id) {
            $site = $this->relationLoaded('exerciseSite')
                ? $this->exerciseSite
                : $this->exerciseSite()->first();

            $siteRole = $site?->roleFor($userId);
            if ($siteRole) {
                return $siteRole;
            }
        }

        // A joined participant with no elevated role is a plain player.
        return $participant ? 'player' : null;
    }

    public function isExerciseController(int $userId): bool
    {
        return in_array($this->exerciseRoleFor($userId), ['commander', 'controller'], true);
    }

    public function isExerciseCommander(int $userId): bool
    {
        return $this->exerciseRoleFor($userId) === 'commander';
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
