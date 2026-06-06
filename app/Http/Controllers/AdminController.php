<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Map;
use App\Models\RouteMap;
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
            // Get all users with their stats
            $users = User::select('id', 'first_name', 'last_name', 'email', 'is_admin', 'created_at')
                ->withCount('maps')
                ->get()
                ->map(function ($user) {
                    // Get maps with routemap counts
                    $maps = Map::where('owner_id', $user->id)
                        ->with('routeMaps')
                        ->get()
                        ->map(function ($map) {
                            return [
                                'id' => $map->id,
                                'title' => $map->title,
                                'routemaps_count' => $map->routeMaps->count(),
                            ];
                        });

                    return [
                        'id' => $user->id,
                        'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                        'email' => $user->email,
                        'is_admin' => $user->is_admin,
                        'maps_count' => $user->maps_count,
                        'maps' => $maps,
                        'created_at' => $user->created_at,
                    ];
                });

            // Calculate totals
            $totalUsers = $users->count();
            $totalMaps = Map::count();
            $totalRouteMaps = RouteMap::count();

            return response()->json([
                'users' => $users,
                'stats' => [
                    'total_users' => $totalUsers,
                    'total_maps' => $totalMaps,
                    'total_routemaps' => $totalRouteMaps,
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
     * Toggle admin status of a user
     */
    public function toggleAdminStatus($userId)
    {
        try {
            // Prevent changing own admin status
            if (Auth::id() == $userId) {
                return response()->json([
                    'message' => 'Je kunt je eigen admin status niet wijzigen',
                    'error' => 'cannot_change_self'
                ], 403);
            }

            $user = User::findOrFail($userId);

            // Toggle admin status
            $user->is_admin = !$user->is_admin;
            $user->save();

            return response()->json([
                'message' => 'Admin status bijgewerkt',
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Gebruiker niet gevonden',
                'error' => 'not_found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij wijziging',
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
