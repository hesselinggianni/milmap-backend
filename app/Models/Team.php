<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasUuids;

    protected $table = 'teams';

    protected $fillable = [
        'owner_id',
        'subscription_id',
        'name',
        'description',
        'color',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'team_id');
    }

    /** Actieve leden (meeliftend op het abonnement); exclusief pending-uitnodigingen. */
    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', TeamMember::STATUS_ACTIVE);
    }

    /** Het team-abonnement dat dit team voedt (seats + entitlements). */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function isOwnedBy(int $userId): bool
    {
        return (int) $this->owner_id === (int) $userId;
    }

    /** Inbegrepen seats bij een team-abonnement (config-default). */
    public function includedSeats(): int
    {
        return (int) config('teams.included_seats', 10);
    }

    /**
     * Seat-gebruik op ABONNEMENT-niveau (owner): één owner kan meerdere teams
     * hebben die dezelfde seat-pool delen. Seats = owner (1) + alle unieke
     * actieve leden over ál zijn teams samen (uniek op e-mail).
     *
     * @return array{included:int, used:int, extra:int}
     */
    public static function seatUsageForOwner(int $ownerId): array
    {
        $included = (int) config('teams.included_seats', 10);

        $teamIds = self::where('owner_id', $ownerId)->pluck('id');

        $distinctMembers = $teamIds->isEmpty()
            ? 0
            : (int) TeamMember::whereIn('team_id', $teamIds)
                ->where('status', TeamMember::STATUS_ACTIVE)
                ->distinct()
                ->count('email');

        $used  = 1 + $distinctMembers;   // owner telt zelf mee
        $extra = max(0, $used - $included);

        return ['included' => $included, 'used' => $used, 'extra' => $extra];
    }

    /** Seat-gebruik voor de owner van dít team (gedeelde pool). */
    public function seatUsage(): array
    {
        return self::seatUsageForOwner((int) $this->owner_id);
    }
}
