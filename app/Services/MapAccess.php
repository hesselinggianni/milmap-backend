<?php

namespace App\Services;

use App\Models\Map;
use App\Models\MapCollaborator;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Request-local authorization; never cache grants across requests or accounts. */
class MapAccess
{
    public function accessibleQuery(User $user): Builder
    {
        return Map::query()->where(function ($q) use ($user) {
            $q->where('owner_id', $user->id)
                ->orWhereHas('collaborators', fn ($c) => $c->where('user_id', $user->id)
                    ->where('status', 'accepted')->whereIn('role', ['viewer', 'editor', 'admin']))
                ->orWhereExists(Mission::query()->selectRaw('1')
                    ->whereColumn('missions.owner_id', 'maps.owner_id')
                    ->whereColumn('missions.map->id', 'maps.id')
                    ->where(fn ($m) => $m->whereNull('map->source')->orWhere('map->source', 'server'))
                    ->whereHas('collaborators', fn ($c) => $c->where('user_id', $user->id)
                        ->where('status', 'accepted')->whereIn('role', ['viewer', 'editor', 'admin'])));
        });
    }

    /** Resolve a whole batch using at most three queries, without loading map features. */
    public function rolesFor(User $user, Collection $maps): array
    {
        $roles = [];
        $remaining = $maps->filter(function (Map $map) use ($user, &$roles) {
            if ((string) $map->owner_id === (string) $user->id) {
                $roles[(string) $map->id] = 'owner';
                return false;
            }
            return true;
        })->keyBy('id');
        if ($remaining->isEmpty()) return $roles;

        foreach (MapCollaborator::whereIn('map_id', $remaining->keys())
            ->where('user_id', $user->id)->where('status', 'accepted')
            ->get(['map_id', 'role']) as $grant) {
            $this->mergeRole($roles, (string) $grant->map_id, $grant->role);
        }

        // A user-controlled mission reference is NOT permission from the map owner.
        // Only the map owner's own missions can delegate access, including old rows.
        $missions = Mission::whereIn('map->id', $remaining->keys())
            ->whereIn('owner_id', $remaining->pluck('owner_id')->unique())
            ->where(fn ($q) => $q->whereNull('map->source')->orWhere('map->source', 'server'))
            ->whereHas('collaborators', fn ($c) => $c->where('user_id', $user->id)->where('status', 'accepted'))
            ->with(['collaborators' => fn ($c) => $c->where('user_id', $user->id)->where('status', 'accepted')])
            ->get(['id', 'owner_id', 'map']);
        foreach ($missions as $mission) {
            $id = (string) ($mission->map['id'] ?? '');
            $map = $remaining->get($id);
            if (!$map || (string) $map->owner_id !== (string) $mission->owner_id) continue;
            foreach ($mission->collaborators as $grant) {
                $this->mergeRole($roles, $id, $grant->role);
            }
        }
        return $roles;
    }

    private function mergeRole(array &$roles, string $id, ?string $role): void
    {
        if (in_array($role, ['editor', 'admin'], true)) $roles[$id] = 'editor';
        elseif ($role === 'viewer' && !isset($roles[$id])) $roles[$id] = 'viewer';
    }
}
