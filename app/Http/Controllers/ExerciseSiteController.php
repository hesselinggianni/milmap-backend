<?php

namespace App\Http\Controllers;

use App\Models\ExerciseSite;
use App\Models\Map;
use App\Models\SiteMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Terrein (Site) — beheer van oefenlocaties: kaart koppelen, huisregels
 * (met versie), en de vaste rollen (commander/controller/player) via site_members.
 *
 * Toegang: de eigenaar en site-members zien het terrein; alleen een commander
 * (eigenaar of member met rol commander) mag instellingen en rollen muteren.
 */
class ExerciseSiteController extends Controller
{
    /**
     * Terreinen die de gebruiker bezit óf waar hij lid van is.
     * GET /api/v1/exercise-sites
     */
    public function index()
    {
        $uid = Auth::id();

        $sites = ExerciseSite::query()
            ->with(['members.user:id,first_name,last_name,email', 'map:id,title'])
            ->where('owner_id', $uid)
            ->orWhereHas('members', fn ($q) => $q->where('user_id', $uid))
            ->latest()
            ->get()
            ->map(fn ($s) => $this->present($s, $uid));

        return response()->json(['sites' => $sites]);
    }

    /**
     * Nieuw terrein aanmaken.
     * POST /api/v1/exercise-sites
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'map_id'      => 'nullable|string|exists:maps,id',
            'center_lat'  => 'nullable|numeric|between:-90,90',
            'center_lng'  => 'nullable|numeric|between:-180,180',
            'house_rules' => 'nullable|string|max:20000',
        ]);

        $this->assertMapOwned($data['map_id'] ?? null);

        $site = ExerciseSite::create([
            'owner_id'      => Auth::id(),
            'name'          => $data['name'],
            'description'   => $data['description'] ?? null,
            'map_id'        => $data['map_id'] ?? null,
            'center_lat'    => $data['center_lat'] ?? null,
            'center_lng'    => $data['center_lng'] ?? null,
            'house_rules'   => $data['house_rules'] ?? null,
            'rules_version' => 1,
            'status'        => 'active',
        ]);

        return response()->json(['site' => $this->present($site->fresh(['members.user', 'map']), Auth::id())], 201);
    }

    /**
     * Eén terrein (eigenaar of lid).
     * GET /api/v1/exercise-sites/{id}
     */
    public function show($id)
    {
        $site = $this->accessibleSite($id);

        return response()->json(['site' => $this->present($site, Auth::id())]);
    }

    /**
     * Terrein-instellingen bijwerken (commander).
     * PUT /api/v1/exercise-sites/{id}
     */
    public function update(Request $request, $id)
    {
        $site = $this->managedSite($id);

        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'map_id'      => 'nullable|string|exists:maps,id',
            'center_lat'  => 'nullable|numeric|between:-90,90',
            'center_lng'  => 'nullable|numeric|between:-180,180',
            'status'      => ['sometimes', Rule::in(['active', 'archived'])],
        ]);

        if (array_key_exists('map_id', $data)) {
            $this->assertMapOwned($data['map_id']);
        }

        $site->update($data);

        return response()->json(['site' => $this->present($site->fresh(['members.user', 'map']), Auth::id())]);
    }

    /**
     * Huisregels bijwerken. Bumpt rules_version wanneer de tekst wijzigt, zodat
     * deelnemers opnieuw akkoord moeten geven vóór actieve deelname.
     * PUT /api/v1/exercise-sites/{id}/rules
     */
    public function updateRules(Request $request, $id)
    {
        $site = $this->managedSite($id);

        $data = $request->validate([
            'house_rules' => 'nullable|string|max:20000',
        ]);

        $newRules = $data['house_rules'] ?? null;
        $changed  = trim((string) $newRules) !== trim((string) $site->house_rules);

        $site->update([
            'house_rules'   => $newRules,
            'rules_version' => $changed ? $site->rules_version + 1 : $site->rules_version,
        ]);

        return response()->json([
            'site'    => $this->present($site->fresh(['members.user', 'map']), Auth::id()),
            'bumped'  => $changed,
        ]);
    }

    /**
     * Terrein verwijderen (eigenaar).
     * DELETE /api/v1/exercise-sites/{id}
     */
    public function destroy($id)
    {
        $site = ExerciseSite::findOrFail($id);

        if (! $site->isOwnedBy(Auth::id())) {
            abort(403, 'Alleen de eigenaar kan dit terrein verwijderen.');
        }

        $site->delete();

        return response()->json(['message' => 'Terrein verwijderd.']);
    }

    // ── Rollen (controleurs / commandanten) beheren ──────────────────

    /**
     * Terrein-rol toekennen aan een gebruiker (commander).
     * POST /api/v1/exercise-sites/{id}/members
     */
    public function addMember(Request $request, $id)
    {
        $site = $this->managedSite($id);

        $data = $request->validate([
            'email'   => 'nullable|email',
            'user_id' => 'nullable|integer|exists:users,id',
            'role'    => ['required', Rule::in(['commander', 'controller', 'player'])],
        ]);

        $user = $this->resolveUser($request);
        if (! $user) {
            return response()->json(['error' => 'Geen MilMap-gebruiker gevonden voor dit e-mailadres.'], 422);
        }

        if ((int) $user->id === (int) $site->owner_id) {
            return response()->json(['error' => 'De eigenaar is al commandant van dit terrein.'], 422);
        }

        $member = SiteMember::updateOrCreate(
            ['site_id' => $site->id, 'user_id' => $user->id],
            ['role' => $data['role'], 'added_by' => Auth::id()],
        );

        $member->load('user:id,first_name,last_name,email');

        return response()->json(['member' => $this->presentMember($member)], 201);
    }

    /**
     * Rol van een terrein-lid wijzigen (commander).
     * PUT /api/v1/exercise-sites/{id}/members/{userId}
     */
    public function updateMemberRole(Request $request, $id, $userId)
    {
        $site = $this->managedSite($id);

        $data = $request->validate([
            'role' => ['required', Rule::in(['commander', 'controller', 'player'])],
        ]);

        $member = SiteMember::where('site_id', $site->id)->where('user_id', $userId)->firstOrFail();
        $member->update(['role' => $data['role']]);
        $member->load('user:id,first_name,last_name,email');

        return response()->json(['member' => $this->presentMember($member)]);
    }

    /**
     * Terrein-lid verwijderen (commander).
     * DELETE /api/v1/exercise-sites/{id}/members/{userId}
     */
    public function removeMember($id, $userId)
    {
        $site = $this->managedSite($id);

        SiteMember::where('site_id', $site->id)->where('user_id', $userId)->delete();

        return response()->json(['message' => 'Terrein-lid verwijderd.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Terrein dat de gebruiker mag inzien (eigenaar of lid), anders 403/404.
     */
    protected function accessibleSite($id): ExerciseSite
    {
        $site = ExerciseSite::with(['members.user:id,first_name,last_name,email', 'map:id,title'])->findOrFail($id);

        $uid = Auth::id();
        if (! $site->isOwnedBy($uid) && $site->roleFor($uid) === null) {
            abort(403, 'Je hebt geen toegang tot dit terrein.');
        }

        return $site;
    }

    /**
     * Terrein waarvan de gebruiker commandant is (mag instellingen/rollen muteren).
     */
    protected function managedSite($id): ExerciseSite
    {
        $site = ExerciseSite::with('members')->findOrFail($id);

        if ($site->roleFor(Auth::id()) !== 'commander') {
            abort(403, 'Alleen een commandant kan dit terrein beheren.');
        }

        return $site;
    }

    protected function assertMapOwned(?string $mapId): void
    {
        if (! $mapId) {
            return;
        }

        $map = Map::findOrFail($mapId);
        if ((int) $map->owner_id !== (int) Auth::id()) {
            abort(403, 'Je kunt alleen je eigen kaart aan een terrein koppelen.');
        }
    }

    protected function resolveUser(Request $request): ?User
    {
        if ($request->filled('user_id')) {
            return User::find($request->user_id);
        }

        if ($request->filled('email')) {
            return User::where('email', mb_strtolower(trim($request->email)))->first();
        }

        return null;
    }

    protected function present(ExerciseSite $site, int $uid): array
    {
        return [
            'id'            => $site->id,
            'name'          => $site->name,
            'description'   => $site->description,
            'map_id'        => $site->map_id,
            'map_title'     => $site->map?->title,
            'center_lat'    => $site->center_lat,
            'center_lng'    => $site->center_lng,
            'house_rules'   => $site->house_rules,
            'rules_version' => $site->rules_version,
            'status'        => $site->status,
            'owner_id'      => (int) $site->owner_id,
            'my_role'       => $site->roleFor($uid),
            'members'       => $site->relationLoaded('members')
                ? $site->members->map(fn ($m) => $this->presentMember($m))->values()
                : [],
            'created_at'    => $site->created_at?->toIso8601String(),
        ];
    }

    protected function presentMember(SiteMember $member): array
    {
        return [
            'id'      => $member->id,
            'user_id' => (int) $member->user_id,
            'role'    => $member->role,
            'name'    => $member->user?->full_name ?? ('#' . $member->user_id),
            'email'   => $member->user?->email,
        ];
    }
}
