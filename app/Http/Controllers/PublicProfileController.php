<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\PublicProfile;
use App\Models\RouteMap;
use App\Models\User;
use App\Services\ConnectManagement;
use App\Support\PublicRoutePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Openbaar profiel: opt-in pagina waar een gebruiker routes (gemaakt of
 * getrackt) toont aan andere MilMap-gebruikers. Een ander kan een route daarna
 * "gebruiken" (klonen) — zie PublicRouteController.
 *
 * Privacy-uitgangspunt: standaard uit; naam = gekozen weergavenaam of handle
 * (nooit de echte naam/e-mail); alleen expliciet gepubliceerde routes.
 */
class PublicProfileController extends Controller
{
    /** Hoeveel routes/tracks de profielpagina per soort meestuurt. */
    private const PAGE_LIMIT = 30;

    /**
     * Eigen profielinstellingen + hoeveel routes er gepubliceerd zijn.
     * GET /api/v1/user/public-profile
     */
    public function mine(): JsonResponse
    {
        $user = Auth::user();
        $profile = PublicProfile::where('user_id', $user->id)->first();

        return response()->json($this->minePayload($user, $profile));
    }

    /**
     * PUT /api/v1/user/public-profile
     * Aanzetten vereist een handle; uitzetten laat handle + gepubliceerde
     * vlaggen staan (komen terug zodra het profiel weer aan gaat).
     */
    public function updateMine(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Zakelijke (Connect-)accounts mogen niets openbaar zetten als de
        // organisatie openbaar delen heeft uitgeschakeld.
        abort_unless(app(ConnectManagement::class)->allows($user, 'public_links'), 403, 'Openbaar delen is door de organisatie uitgeschakeld.');

        $request->merge(['handle' => PublicProfile::normalizeHandle($request->input('handle'))]);
        $existing = PublicProfile::where('user_id', $user->id)->first();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'handle' => [
                Rule::requiredIf(fn () => $request->boolean('enabled') && ! $existing),
                'nullable', 'string', 'regex:/^[a-z0-9_]{3,30}$/',
                Rule::notIn(PublicProfile::RESERVED_HANDLES),
                Rule::unique('public_profiles', 'handle')->ignore($existing?->id),
            ],
            'display_name' => ['nullable', 'string', 'max:60'],
            'bio' => ['nullable', 'string', 'max:300'],
            'hide_endpoints' => ['sometimes', 'boolean'],
        ], [
            'handle.regex' => 'Gebruik 3 tot 30 tekens: kleine letters, cijfers en underscores.',
            'handle.unique' => 'Deze gebruikersnaam is al in gebruik.',
            'handle.not_in' => 'Deze gebruikersnaam is gereserveerd.',
            'handle.required' => 'Kies een gebruikersnaam om je profiel openbaar te maken.',
        ]);

        if (! $existing && empty($data['handle'])) {
            // Uitzetten zonder dat er ooit een profiel was: niets te bewaren.
            return response()->json($this->minePayload($user, null));
        }

        $profile = PublicProfile::updateOrCreate(
            ['user_id' => $user->id],
            array_filter([
                'handle' => $data['handle'] ?? null,
                'enabled' => $data['enabled'],
                'hide_endpoints' => $data['hide_endpoints'] ?? null,
            ], fn ($v) => $v !== null) + [
                'display_name' => $this->nullIfBlank($data['display_name'] ?? null),
                'bio' => $this->nullIfBlank($data['bio'] ?? null),
            ]
        );

        return response()->json($this->minePayload($user, $profile));
    }

    /**
     * Openbaar profiel met gepubliceerde routes (publiek, geen login nodig).
     * GET /api/v1/profiles/{handle}
     */
    public function show(string $handle): JsonResponse
    {
        $profile = self::visibleProfile($handle);
        $user = $profile->user;
        $viewerId = (int) optional(auth('sanctum')->user())->id;
        $blocked = $viewerId && Schema::hasTable('user_blocks')
            ? DB::table('user_blocks')->where(['blocker_id' => $viewerId, 'blocked_id' => $user->id])->exists()
            : false;
        abort_if($blocked, 404);

        $routes = self::publicRoutesQuery($user->id)->latest('published_at')->limit(self::PAGE_LIMIT)->get();
        $activities = self::publicActivitiesQuery($user->id)->latest('started_at')->limit(self::PAGE_LIMIT)->get();

        $routeItems = PublicRoutePresenter::routeSummaries($routes);
        $trackItems = PublicRoutePresenter::trackSummaries($activities, $profile);

        return response()->json([
            'profile' => self::present($profile),
            'stats' => self::stats($user->id),
            'timeline' => PublicRoutePresenter::timeline($routeItems, $trackItems),
            'routes' => $routeItems,
            'tracks' => $trackItems,
            'is_me' => (int) optional(auth('sanctum')->user())->id === (int) $user->id,
            'social' => $this->socialPayload($user->id, $viewerId),
        ]);
    }

    private function socialPayload(int $userId, int $viewerId): array
    {
        if (! Schema::hasTable('public_profile_follows')) {
            return ['followers' => 0, 'following' => 0, 'is_following' => false, 'is_blocked' => false];
        }
        return [
            'followers' => DB::table('public_profile_follows')->where('followed_id', $userId)->count(),
            'following' => DB::table('public_profile_follows')->where('follower_id', $userId)->count(),
            'is_following' => $viewerId > 0 && DB::table('public_profile_follows')->where(['follower_id' => $viewerId, 'followed_id' => $userId])->exists(),
            'is_blocked' => $viewerId > 0 && Schema::hasTable('user_blocks') && DB::table('user_blocks')->where(['blocker_id' => $viewerId, 'blocked_id' => $userId])->exists(),
        ];
    }

    /**
     * Zoek openbare profielen op handle of weergavenaam.
     * GET /api/v1/profiles?q=
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['profiles' => []]);
        }
        // % en _ letterlijk zoeken (handles bevatten vaak een _). Eigen escape-teken
        // i.p.v. de backslash: MySQL kent die standaard, SQLite niet.
        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q));

        $profiles = PublicProfile::visible()
            ->where('user_id', '!=', Auth::id())
            ->where(function ($query) use ($like) {
                $query->whereRaw("handle LIKE ? ESCAPE '!'", [$like . '%'])
                    ->orWhereRaw("LOWER(display_name) LIKE ? ESCAPE '!'", ['%' . $like . '%']);
            })
            ->with('user:id,avatar_path')
            ->orderBy('handle')
            ->limit(20)
            ->get()
            ->map(fn (PublicProfile $p) => self::present($p))
            ->values();

        return response()->json(['profiles' => $profiles]);
    }

    // ── Gedeelde helpers (ook gebruikt door PublicRouteController) ──────────

    /** Profiel dat aan staat en waarvan de eigenaar openbaar mág delen; anders 404. */
    public static function visibleProfile(string $handle): PublicProfile
    {
        $profile = PublicProfile::visible()
            ->where('handle', PublicProfile::normalizeHandle($handle))
            ->with('user')
            ->firstOrFail();

        abort_unless($profile->user && app(ConnectManagement::class)->allows($profile->user, 'public_links'), 404);

        return $profile;
    }

    /** Gepubliceerde routekaarten van een gebruiker (nooit organisatie-data). */
    public static function publicRoutesQuery(int $userId)
    {
        return RouteMap::query()
            ->when(Schema::hasTable('route_map_photos'), fn ($q) => $q->with('photos:id,route_map_id,url'))
            ->where('owner_id', $userId)
            ->where('is_public', true)
            ->whereNull('connect_organization_id');
    }

    /**
     * Eén gepubliceerde routekaart op id óf op SEO-slug ("avervoorderpad-a319f662":
     * de laatste 8 hex-tekens zijn het begin van het uuid).
     */
    public static function findPublicRoute(int $userId, string $idOrSlug)
    {
        $query = self::publicRoutesQuery($userId);
        if (preg_match('/-([0-9a-f]{8})$/i', $idOrSlug, $m) && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $idOrSlug)) {
            return $query->where('id', 'like', strtolower($m[1]) . '%')->firstOrFail();
        }

        return $query->findOrFail($idOrSlug);
    }

    /** Gepubliceerde tracks van een gebruiker (nooit organisatie-data). */
    public static function publicActivitiesQuery(int $userId)
    {
        return Activity::query()
            ->when(Schema::hasTable('activity_photos'), fn ($q) => $q->with('photos:id,activity_id,url'))
            ->where('user_id', $userId)
            ->where('is_public', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('maps')
                ->whereColumn('maps.id', 'activities.map_id')
                ->whereNotNull('maps.connect_organization_id'));
    }

    /**
     * Totalen over ALLES wat gepubliceerd is (niet alleen de eerste pagina).
     * Alleen sommen: geen tijdstippen of locaties.
     */
    public static function stats(int $userId): array
    {
        $r = self::publicRoutesQuery($userId)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total_distance),0) as dist, COALESCE(SUM(total_elevation),0) as elev')
            ->first();
        $a = self::publicActivitiesQuery($userId)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(distance_m),0) as dist, COALESCE(SUM(elevation_gain_m),0) as elev, COALESCE(SUM(moving_time_s),0) as secs')
            ->first();

        return [
            'routes' => (int) $r->n,
            'tracks' => (int) $a->n,
            'track_distance_m' => (int) round((float) $a->dist),
            'track_elevation_m' => (int) round((float) $a->elev),
            'track_moving_time_s' => (int) $a->secs,
            'distance_m' => (int) round((float) $r->dist + (float) $a->dist),
            'elevation_m' => (int) round((float) $r->elev + (float) $a->elev),
            'moving_time_s' => (int) $a->secs,
        ];
    }

    /** Wat een vreemde van het profiel ziet. */
    public static function present(PublicProfile $profile): array
    {
        return [
            'handle' => $profile->handle,
            'name' => $profile->publicName(),
            'bio' => $profile->bio,
            'avatar_url' => $profile->user?->avatar_url,
            'member_since' => $profile->created_at?->toDateString(),
        ];
    }

    private function minePayload(User $user, ?PublicProfile $profile): array
    {
        return [
            'profile' => $profile ? [
                'enabled' => (bool) $profile->enabled,
                'handle' => $profile->handle,
                'display_name' => $profile->display_name,
                'bio' => $profile->bio,
                'hide_endpoints' => (bool) $profile->hide_endpoints,
            ] : null,
            'published' => [
                'routes' => RouteMap::where('owner_id', $user->id)->where('is_public', true)->count(),
                'tracks' => Activity::where('user_id', $user->id)->where('is_public', true)->count(),
            ],
            'endpoint_trim_m' => PublicProfile::ENDPOINT_TRIM_M,
        ];
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
