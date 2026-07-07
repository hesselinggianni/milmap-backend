<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Map;
use App\Models\RouteMap;
use App\Models\Mission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class AdminController extends Controller
{
    /**
     * Get admin dashboard statistics
     */
    public function getDashboardStats()
    {
        try {
            // Alleen tellingen — bewust geen kaarttitels/datums inladen.
            $baseUsers = User::select('id', 'first_name', 'last_name', 'email', 'is_admin', 'created_at')
                ->withCount('maps')
                ->get();

            // Per-gebruiker tellingen van routekaarten en missies in twee
            // groeps-queries (geen N+1, en zonder de rijen/titels te laden).
            $routeMapCounts = RouteMap::selectRaw('owner_id, COUNT(*) as c')
                ->groupBy('owner_id')
                ->pluck('c', 'owner_id');
            $missionCounts = Mission::selectRaw('owner_id, COUNT(*) as c')
                ->groupBy('owner_id')
                ->pluck('c', 'owner_id');

            $users = $baseUsers->map(function ($user) use ($routeMapCounts, $missionCounts) {
                return [
                    'id'               => $user->id,
                    'name'             => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                    'email'            => $user->email,
                    'is_admin'         => (bool) $user->is_admin,
                    'maps_count'       => (int) $user->maps_count,
                    'route_maps_count' => (int) ($routeMapCounts[$user->id] ?? 0),
                    'missions_count'   => (int) ($missionCounts[$user->id] ?? 0),
                    'created_at'       => $user->created_at,
                ];
            })->values();

            return response()->json([
                'users' => $users,
                'stats' => [
                    'total_users'     => $baseUsers->count(),
                    'total_maps'      => Map::count(),
                    'total_routemaps' => RouteMap::count(),
                    'total_missions'  => Mission::count(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij ophalen statistieken',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get full detail for a single user (profile, maps, teams, billing).
     */
    public function getUser($userId)
    {
        try {
            $user = User::with([
                'teams' => fn ($q) => $q->withCount('members'),
                'subscriptions',
            ])->findOrFail($userId);

            // Alleen tellingen — geen titels/datums van kaarten, routekaarten
            // of missies inladen (bewust beperkt voor het admin-overzicht).
            $mapsCount      = Map::where('owner_id', $user->id)->count();
            $routeMapsCount = RouteMap::where('owner_id', $user->id)->count();
            $missionsCount  = Mission::where('owner_id', $user->id)->count();

            return response()->json([
                'user' => [
                    'id'                => $user->id,
                    'first_name'        => $user->first_name,
                    'last_name'         => $user->last_name,
                    'name'              => $user->full_name,
                    'email'             => $user->email,
                    'is_admin'          => (bool) $user->is_admin,
                    'language'          => $user->language,
                    'view_only'         => $user->isViewOnly(),
                    'email_verified_at' => $user->email_verified_at,
                    'created_at'        => $user->created_at,
                    'updated_at'        => $user->updated_at,
                    'plan'              => $user->plan(),
                    'subscribed'        => $user->subscribed(),
                    'trial_ends_at'     => $user->trial_ends_at,
                    'on_trial'          => $user->onAppTrial(),
                    'trial_days_left'   => $user->trialDaysLeft(),
                    'has_premium'       => $user->hasPremiumAccess(),
                    'stripe_id'         => $user->stripe_id,
                    'pm_type'           => $user->pm_type,
                    'pm_last_four'      => $user->pm_last_four,
                ],
                'teams' => $user->teams->map(function ($team) {
                    return [
                        'id'            => $team->id,
                        'name'          => $team->name,
                        'description'   => $team->description,
                        'color'         => $team->color,
                        'members_count' => $team->members_count,
                    ];
                }),
                'subscriptions' => $user->subscriptions->map(function ($sub) {
                    return [
                        'id'            => $sub->id,
                        'type'          => $sub->type,
                        'stripe_status' => $sub->stripe_status,
                        'stripe_price'  => $sub->stripe_price,
                        'quantity'      => $sub->quantity,
                        'trial_ends_at' => $sub->trial_ends_at,
                        'ends_at'       => $sub->ends_at,
                        'created_at'    => $sub->created_at,
                    ];
                }),
                'stats' => [
                    'total_maps'          => $mapsCount,
                    'total_routemaps'     => $routeMapsCount,
                    'total_missions'      => $missionsCount,
                    'total_teams'         => $user->teams->count(),
                    'total_subscriptions' => $user->subscriptions->count(),
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Gebruiker niet gevonden',
                'error'   => 'not_found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij ophalen gebruiker',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a user and cascade related data
     */
    public function deleteUser($userId)
    {
        try {
            // Prevent self-deletion
            if (Auth::id() == $userId) {
                return response()->json([
                    'message' => 'Je kunt jezelf niet verwijderen',
                    'error' => 'cannot_delete_self'
                ], 403);
            }

            $user = User::findOrFail($userId);

            // Delete user (this cascades to maps and routemaps due to foreign key constraints)
            $user->delete();

            return response()->json([
                'message' => 'Gebruiker verwijderd',
                'user_id' => $userId
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Gebruiker niet gevonden',
                'error' => 'not_found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij verwijdering',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send password reset link to user
     */
    public function resetUserPassword($userId)
    {
        try {
            $user = User::findOrFail($userId);

            // Send password reset notification
            $user->sendPasswordResetNotification(Password::createToken($user));

            return response()->json([
                'message' => 'Wachtwoord reset link verzonden naar ' . $user->email
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Gebruiker niet gevonden',
                'error' => 'not_found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij verzending',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/v1/admin/users/{userId}/grant-access
     * Ken een gebruiker (extra) gratis premium-toegang toe door de app-proef te
     * verlengen. Zet `trial_ends_at` op N dagen vanaf nu — of vanaf de huidige
     * proefeinddatum als die verder in de toekomst ligt — zodat een lopende proef
     * nooit wordt ingekort. Dit geeft direct volledige toegang
     * (User::hasPremiumAccess()) zonder Stripe, ideaal om een klant/eenheid te
     * onboarden of een verlopen proef te verlengen.
     */
    public function grantAccess(Request $request, $userId)
    {
        $data = $request->validate([
            'days' => 'required|integer|min:1|max:3650',
        ]);

        try {
            $user = User::findOrFail($userId);

            $base = ($user->trial_ends_at && $user->trial_ends_at->isFuture())
                ? $user->trial_ends_at->copy()
                : now();
            $newEnd = $base->addDays((int) $data['days']);

            $user->forceFill(['trial_ends_at' => $newEnd])->save();

            // Gratis toegang telt als echtheidsbewijs → e-mail meteen verifiëren
            // zodat de verificatie-wall deze klant niet alsnog blokkeert.
            $user->markEmailVerified();

            return response()->json([
                'message'       => "Gratis toegang verleend tot {$newEnd->format('d-m-Y')}.",
                'trial_ends_at' => $newEnd->toIso8601String(),
                'premium'       => $user->fresh()->premiumState(),
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Gebruiker niet gevonden', 'error' => 'not_found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Fout bij toekennen toegang', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/admin/users/{userId}/verify-email
     * Markeer het e-mailadres van een gebruiker handmatig als geverifieerd (bv.
     * na telefonisch/persoonlijk contact), zodat de verificatie-wall verdwijnt.
     */
    public function verifyUserEmail($userId)
    {
        try {
            $user = User::findOrFail($userId);
            $changed = $user->markEmailVerified();

            return response()->json([
                'message'           => $changed
                    ? 'E-mailadres gemarkeerd als geverifieerd.'
                    : 'E-mailadres was al geverifieerd.',
                'email_verified_at' => $user->fresh()->email_verified_at,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Gebruiker niet gevonden', 'error' => 'not_found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Fout bij verifiëren', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/admin/client-errors
     * Recent front-end errors captured from the browser (rolling JSON log),
     * so an admin can review them and forward them to Claude for a fix.
     */
    public function clientErrors()
    {
        $file = storage_path('logs/frontend-errors.json');
        $errors = [];

        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $errors = $raw ? (json_decode($raw, true) ?: []) : [];
        }

        return response()->json([
            'errors' => $errors,
            'count'  => count($errors),
        ]);
    }

    /**
     * DELETE /api/v1/admin/client-errors
     * Clear the captured front-end error log.
     */
    public function clearClientErrors()
    {
        $file = storage_path('logs/frontend-errors.json');
        if (file_exists($file)) {
            @file_put_contents($file, json_encode([]));
        }

        return response()->json(['ok' => true]);
    }
}
