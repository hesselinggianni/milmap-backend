<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapCollaborator;
use App\Models\Mission;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\TeamSeatBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function __construct(
        protected InvitationService $service,
        protected TeamSeatBillingService $seats,
    ) {
    }

    /**
     * Teams owned by the authenticated user (with their members).
     * GET /api/v1/teams
     */
    public function index()
    {
        $teams = Team::where('owner_id', Auth::id())
            ->with(['members.user:id,first_name,last_name,email'])
            ->latest()
            ->get()
            ->map(fn ($t) => $this->present($t));

        return response()->json([
            'teams'                => $teams,
            'can_own_team'         => $this->ownerTeamSubscription(Auth::user()) !== null,
            'included_seats'       => (int) config('teams.included_seats', 10),
            'extra_seat_price_eur' => (float) config('teams.extra_seat_price_eur', 2.0),
            'modules'              => User::PREMIUM_FEATURES,
        ]);
    }

    /**
     * Create a new team. Only an owner of an active TEAM subscription
     * (team_monthly / team_yearly) may create one. Een owner mag meerdere teams
     * hebben; die delen dezelfde seat-pool van het abonnement.
     * POST /api/v1/teams
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $sub  = $this->ownerTeamSubscription($user);

        if (! $sub) {
            return response()->json([
                'error' => 'Een team aanmaken kan alleen met een actief team-abonnement.',
                'code'  => 'team_subscription_required',
            ], 403);
        }

        $data = $request->validate([
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'color'       => 'nullable|string|max:16',
        ]);

        $team = Team::create([
            'owner_id'        => $user->id,
            'subscription_id' => $sub->id,
            'name'            => $data['name'],
            'description'     => $data['description'] ?? null,
            'color'           => $data['color'] ?? null,
        ]);

        $team->load(['members.user:id,first_name,last_name,email']);

        return response()->json(['team' => $this->present($team)], 201);
    }

    /**
     * A single team (owner only).
     * GET /api/v1/teams/{team}
     */
    public function show($id)
    {
        $team = $this->ownedTeam($id);

        return response()->json(['team' => $this->present($team)]);
    }

    /**
     * Update a team's name / description / color.
     * PUT /api/v1/teams/{team}
     */
    public function update(Request $request, $id)
    {
        $team = $this->ownedTeam($id);

        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'color'       => 'nullable|string|max:16',
        ]);

        $team->update($data);
        $team->load(['members.user:id,first_name,last_name,email']);

        return response()->json(['team' => $this->present($team)]);
    }

    /**
     * Delete a team. Frees the seats on the team subscription.
     * DELETE /api/v1/teams/{team}
     */
    public function destroy($id)
    {
        $team = $this->ownedTeam($id);
        $team->members()->delete();
        $team->delete();
        // Seats terug naar Stripe (extra-seat item verdwijnt) — best effort.
        $this->seats->sync($team);

        return response()->json(['message' => 'Team verwijderd.']);
    }

    /**
     * Add a member to a team, by user_id (a known contact) or by raw e-mail.
     *
     * "Eén abonnement per gebruiker": zit de gebruiker al in een ánder team →
     * geweigerd. Heeft de gebruiker een eigen abonnement → membership wordt
     * `pending` (de gebruiker doet de keuze-flow). Anders → direct `active`.
     * POST /api/v1/teams/{team}/members
     */
    public function addMember(Request $request, $id)
    {
        $team = $this->ownedTeam($id);

        $request->validate([
            'email'   => 'nullable|email',
            'user_id' => 'nullable|integer|exists:users,id',
            'role'    => ['nullable', Rule::in(['member', 'guest'])],
        ]);

        $role = $request->input('role', TeamMember::ROLE_MEMBER);

        $email = null;
        $userId = null;

        if ($request->filled('user_id')) {
            $user   = User::findOrFail($request->user_id);
            $email  = mb_strtolower($user->email);
            $userId = $user->id;
        } elseif ($request->filled('email')) {
            $email = mb_strtolower(trim($request->email));
            $userId = User::where('email', $email)->value('id');
        } else {
            return response()->json(['error' => 'Geef een e-mailadres of gebruiker op.'], 422);
        }

        if (TeamMember::where('team_id', $team->id)->where('email', $email)->exists()) {
            return response()->json(['error' => 'Dit teamlid staat al in het team.'], 422);
        }

        $status = TeamMember::STATUS_ACTIVE;

        if ($userId) {
            $account = User::find($userId);

            // Al lid van een ander team? Kan niet — één team per gebruiker.
            $inOtherTeam = TeamMember::where('user_id', $userId)
                ->where('team_id', '!=', $team->id)
                ->exists();
            if ($inOtherTeam) {
                return response()->json([
                    'error' => 'Deze gebruiker valt al onder een ander team-abonnement.',
                    'code'  => 'already_in_team',
                ], 422);
            }

            // Eigen actief abonnement? Dan eerst de keuze-flow (pending).
            if ($account && $account->activeSubscription()) {
                $status = TeamMember::STATUS_PENDING;
            }
        }

        $member = TeamMember::create([
            'team_id'     => $team->id,
            'email'       => $email,
            'user_id'     => $userId,
            'added_by'    => Auth::id(),
            'role'        => $role,
            'permissions' => null,
            'status'      => $status,
        ]);

        // Alleen actieve leden tellen als seat → sync facturatie.
        if ($status === TeamMember::STATUS_ACTIVE) {
            $this->seats->sync($team->fresh());
        }

        $member->load('user:id,first_name,last_name,email');

        return response()->json([
            'member'      => $this->presentMember($member),
            'seat_status' => $this->seatInfo($team->fresh()),
        ], 201);
    }

    /**
     * Update a member's role and/or per-module permissions.
     * PUT /api/v1/teams/{team}/members/{member}
     */
    public function updateMember(Request $request, $id, $memberId)
    {
        $team   = $this->ownedTeam($id);
        $member = TeamMember::where('team_id', $team->id)->findOrFail($memberId);

        $data = $request->validate([
            'role'          => ['sometimes', Rule::in(['member', 'guest'])],
            'permissions'   => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['boolean'],
        ]);

        if (array_key_exists('role', $data)) {
            $member->role = $data['role'];
        }
        if (array_key_exists('permissions', $data)) {
            // Alleen bekende modules bewaren.
            $perms = $data['permissions'] ?? [];
            $member->permissions = array_intersect_key($perms, array_flip(User::PREMIUM_FEATURES)) ?: null;
        }
        $member->save();
        $member->load('user:id,first_name,last_name,email');

        return response()->json(['member' => $this->presentMember($member)]);
    }

    /**
     * Remove a member from a team. Frees a seat.
     * DELETE /api/v1/teams/{team}/members/{member}
     */
    public function removeMember($id, $memberId)
    {
        $team   = $this->ownedTeam($id);
        $member = TeamMember::where('team_id', $team->id)->findOrFail($memberId);
        $wasActive = $member->isActive();
        $member->delete();

        if ($wasActive) {
            $this->seats->sync($team->fresh());
        }

        return response()->json([
            'message'     => 'Teamlid verwijderd.',
            'seat_status' => $this->seatInfo($team->fresh()),
        ]);
    }

    // ── Keuze-flow (invited user) ───────────────────────────────────

    /**
     * Pending team-uitnodigingen voor de ingelogde gebruiker (die al een eigen
     * abonnement heeft en moet kiezen).
     * GET /api/v1/team-memberships/pending
     */
    public function pendingForMe()
    {
        $rows = TeamMember::where('user_id', Auth::id())
            ->where('status', TeamMember::STATUS_PENDING)
            ->with('team.owner:id,first_name,last_name')
            ->get()
            ->map(fn (TeamMember $m) => [
                'membership_id' => $m->id,
                'team_id'       => $m->team_id,
                'team_name'     => $m->team?->name,
                'owner_name'    => $m->team?->owner?->full_name,
                'role'          => $m->role,
            ]);

        return response()->json(['pending' => $rows]);
    }

    /**
     * Beslis over een pending uitnodiging.
     * POST /api/v1/team-memberships/{member}/decide  body: { action: keep|join }
     *  - keep: uitnodiging afwijzen (je houdt je eigen abonnement).
     *  - join: eigen abonnement opzeggen (aan einde periode) en meeliften.
     */
    public function decide(Request $request, $memberId)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['keep', 'join'])],
        ]);

        $member = TeamMember::where('id', $memberId)
            ->where('user_id', Auth::id())
            ->where('status', TeamMember::STATUS_PENDING)
            ->firstOrFail();

        if ($data['action'] === 'keep') {
            $member->delete();

            return response()->json(['ok' => true, 'result' => 'kept_own']);
        }

        // Join: eigen abonnement opzeggen (blijft actief tot einde periode; zolang
        // dat loopt gebruik je nog je eigen plan, daarna lift je mee), dan actief.
        $user = Auth::user();
        $own  = $user->activeSubscription();
        if ($own && $own->stripe_id && config('billing.stripe_secret')) {
            try {
                $stripe = new \Stripe\StripeClient(['api_key' => config('billing.stripe_secret')]);
                $stripeSub = $stripe->subscriptions->update($own->stripe_id, [
                    'cancel_at_period_end' => true,
                ]);
                $endsAt = isset($stripeSub->current_period_end)
                    ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end)
                    : now();
                $own->update(['ends_at' => $endsAt]);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Kon je huidige abonnement niet opzeggen: ' . $e->getMessage()], 422);
            }
        }

        $member->update(['status' => TeamMember::STATUS_ACTIVE]);
        $this->seats->sync($member->team()->first());

        return response()->json(['ok' => true, 'result' => 'joined']);
    }

    /**
     * Invite an entire team to a mission or map: fan out one e-mail invitation
     * per team member through the shared InvitationService.
     * POST /api/v1/teams/{team}/invite
     */
    public function invite(Request $request, $id)
    {
        $team = $this->ownedTeam($id);

        $data = $request->validate([
            'invitable_type' => ['required', Rule::in(['mission', 'map'])],
            'invitable_id'   => 'required|string',
            'role'           => ['nullable', Rule::in(['viewer', 'editor', 'admin'])],
        ]);

        $resource = $data['invitable_type'] === 'mission'
            ? Mission::find($data['invitable_id'])
            : Map::find($data['invitable_id']);

        if (! $resource) {
            return response()->json(['error' => 'De missie of kaart bestaat niet.'], 404);
        }

        if (! $this->canManageResource($resource, Auth::id())) {
            return response()->json(['error' => 'Je hebt geen rechten om hier mensen voor uit te nodigen.'], 403);
        }

        $role    = $data['role'] ?? 'viewer';
        $inviter = Auth::user();

        $invited = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($team->members as $member) {
            $result = $this->service->createInvite($resource, $member->email, $role, $inviter);
            if (! empty($result['ok'])) {
                $invited++;
                if (($result['email_sent'] ?? true) === false) {
                    $failed++;
                }
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'message' => "Team \"{$team->name}\" uitgenodigd: {$invited} uitgenodigd, {$skipped} overgeslagen.",
            'invited' => $invited,
            'skipped' => $skipped,
            'email_failed' => $failed,
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /** Het actieve team-abonnement van deze gebruiker, of null. */
    protected function ownerTeamSubscription(?User $user)
    {
        if (! $user) {
            return null;
        }
        $sub = $user->activeSubscription();
        if ($sub && User::tierForPrice($sub->stripe_price) === 'team') {
            return $sub;
        }

        return null;
    }

    protected function ownedTeam($id): Team
    {
        $team = Team::with(['members.user:id,first_name,last_name,email'])->findOrFail($id);

        if (! $team->isOwnedBy(Auth::id())) {
            abort(403, 'Dit is niet jouw team.');
        }

        return $team;
    }

    protected function canManageResource($resource, int $userId): bool
    {
        if ($resource instanceof Mission) {
            return $resource->canManage($userId);
        }

        if ((int) $resource->owner_id === $userId) {
            return true;
        }

        return MapCollaborator::where('map_id', $resource->id)
            ->where('user_id', $userId)
            ->where('status', 'accepted')
            ->where('role', 'admin')
            ->exists();
    }

    protected function seatInfo(Team $team): array
    {
        // Seats zijn owner-breed (gedeelde pool over al zijn teams).
        $usage = Team::seatUsageForOwner((int) $team->owner_id);

        return [
            'included_seats'       => $usage['included'],
            'used_seats'           => $usage['used'],
            'extra_seats'          => $usage['extra'],
            'extra_seat_price_eur' => (float) config('teams.extra_seat_price_eur', 2.0),
            'shared_pool'          => true,
        ];
    }

    protected function present(Team $team): array
    {
        return [
            'id'            => $team->id,
            'name'          => $team->name,
            'description'   => $team->description,
            'color'         => $team->color,
            'members_count' => $team->members->count(),
            'members'       => $team->members->map(fn ($m) => $this->presentMember($m))->values(),
            'seat_status'   => $this->seatInfo($team),
            'created_at'    => $team->created_at?->toIso8601String(),
        ];
    }

    protected function presentMember(TeamMember $member): array
    {
        return [
            'id'          => $member->id,
            'email'       => $member->email,
            'user_id'     => $member->user_id,
            'name'        => $member->user?->full_name ?? $member->email,
            'role'        => $member->role,
            'status'      => $member->status,
            'permissions' => is_array($member->permissions) ? $member->permissions : null,
        ];
    }
}
