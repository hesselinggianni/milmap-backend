<?php

namespace App\Http\Controllers;

use App\Events\MissionLocationUpdated;
use App\Models\Mission;
use App\Models\MissionTrack;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MissionTrackController extends Controller
{
    /**
     * Batch-store GPS track points for the authenticated user.
     * POST /api/v1/missions/{id}/tracks
     *
     * Body: { points: [{ lat, lng, altitude?, speed?, heading?, accuracy?, recorded_at }] }
     * Up to 50 points per request (client batches every ~5 s).
     */
    public function store(Request $request, $missionId)
    {
        $userId = Auth::id();
        $mission = Mission::findOrFail($missionId);

        if (! $mission->hasAccess($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'points'              => ['required', 'array', 'min:1', 'max:50'],
            'points.*.lat'        => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng'        => ['required', 'numeric', 'between:-180,180'],
            'points.*.altitude'   => ['nullable', 'numeric'],
            'points.*.speed'      => ['nullable', 'numeric', 'min:0'],
            'points.*.heading'    => ['nullable', 'numeric', 'between:0,360'],
            'points.*.accuracy'   => ['nullable', 'numeric', 'min:0'],
            'points.*.recorded_at'=> ['required', 'date'],
        ]);

        $rows = array_map(fn ($p) => [
            'mission_id'  => $mission->id,
            'user_id'     => $userId,
            'lat'         => $p['lat'],
            'lng'         => $p['lng'],
            'altitude'    => $p['altitude'] ?? null,
            'speed'       => $p['speed'] ?? null,
            'heading'     => $p['heading'] ?? null,
            'accuracy'    => $p['accuracy'] ?? null,
            'recorded_at' => $p['recorded_at'],
            'created_at'  => now(),
            'updated_at'  => now(),
        ], $data['points']);

        MissionTrack::insert($rows);

        // Broadcast this participant's latest position to the mission presence
        // channel so teammates see live movement on the mission map. The client
        // only uploads while the user has consented to share, so being broadcast
        // here is always opt-in. Best-effort: never let a broadcast failure break
        // track storage.
        try {
            $last = end($data['points']);
            $user = Auth::user();
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? 'Unknown');

            broadcast(new MissionLocationUpdated(
                missionId:  (string) $mission->id,
                userId:     (int) $userId,
                userName:   $name,
                lat:        (float) $last['lat'],
                lng:        (float) $last['lng'],
                heading:    isset($last['heading']) ? (float) $last['heading'] : null,
                speed:      isset($last['speed']) ? (float) $last['speed'] : null,
                accuracy:   isset($last['accuracy']) ? (float) $last['accuracy'] : null,
                recordedAt: $last['recorded_at'] ?? null,
            ));
        } catch (\Throwable $e) {
            Log::warning('MissionLocationUpdated broadcast failed: ' . $e->getMessage());
        }

        return response()->json(['stored' => count($rows)], 201);
    }

    /**
     * Latest GPS position per participant (for live admin view).
     * GET /api/v1/missions/{id}/tracks/live
     * Access: owner or admin collaborator.
     */
    public function live($missionId)
    {
        $userId = Auth::id();
        $mission = Mission::findOrFail($missionId);

        // Any mission participant may view live teammate positions on the map.
        // (Appearing on the map is still opt-in — see store(): a participant is
        //  only broadcast/persisted while they consent to share their location.)
        if (! $mission->hasAccess($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Subquery: latest recorded_at per (mission, user)
        $latest = MissionTrack::select(
                DB::raw('MAX(id) as max_id')
            )
            ->where('mission_id', $mission->id)
            ->groupBy('user_id')
            ->pluck('max_id');

        $positions = MissionTrack::with('user:id,first_name,last_name,email')
            ->whereIn('id', $latest)
            ->get()
            ->map(fn ($t) => [
                'user_id'     => $t->user_id,
                'name'        => trim(($t->user->first_name ?? '') . ' ' . ($t->user->last_name ?? '')) ?: $t->user->email,
                'lat'         => $t->lat,
                'lng'         => $t->lng,
                'altitude'    => $t->altitude,
                'speed'       => $t->speed,
                'heading'     => $t->heading,
                'recorded_at' => $t->recorded_at,
            ]);

        return response()->json(['positions' => $positions]);
    }

    /**
     * Full GPS track for one user (for debrief / replay).
     * GET /api/v1/missions/{id}/tracks/{userId}
     * Access: the user themselves, or owner/admin.
     */
    public function userTrack($missionId, $targetUserId)
    {
        $userId = Auth::id();
        $mission = Mission::findOrFail($missionId);

        $isSelf  = (int) $targetUserId === (int) $userId;
        $canSee  = $mission->canEdit($userId) || $isSelf;

        if (! $canSee || ! $mission->hasAccess($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $points = MissionTrack::where('mission_id', $mission->id)
            ->where('user_id', $targetUserId)
            ->orderBy('recorded_at')
            ->get(['lat', 'lng', 'altitude', 'speed', 'heading', 'accuracy', 'recorded_at'])
            ->map(fn ($t) => [
                'lat'         => $t->lat,
                'lng'         => $t->lng,
                'altitude'    => $t->altitude,
                'speed'       => $t->speed,
                'heading'     => $t->heading,
                'accuracy'    => $t->accuracy,
                'recorded_at' => $t->recorded_at,
            ]);

        // Basic stats
        $count    = $points->count();
        $distanceM = 0;
        $maxAlt    = null;
        $minAlt    = null;
        $climbM    = 0;

        for ($i = 1; $i < $count; $i++) {
            $prev = $points[$i - 1];
            $curr = $points[$i];
            $distanceM += $this->haversine($prev['lat'], $prev['lng'], $curr['lat'], $curr['lng']);

            if ($curr['altitude'] !== null) {
                $maxAlt = $maxAlt === null ? $curr['altitude'] : max($maxAlt, $curr['altitude']);
                $minAlt = $minAlt === null ? $curr['altitude'] : min($minAlt, $curr['altitude']);
                if ($i > 1 && $points[$i - 1]['altitude'] !== null) {
                    $delta = $curr['altitude'] - $points[$i - 1]['altitude'];
                    if ($delta > 0) $climbM += $delta;
                }
            }
        }

        return response()->json([
            'points' => $points,
            'stats'  => [
                'distance_m'  => round($distanceM),
                'climb_m'     => round($climbM),
                'max_alt_m'   => $maxAlt !== null ? round($maxAlt) : null,
                'min_alt_m'   => $minAlt !== null ? round($minAlt) : null,
                'point_count' => $count,
            ],
        ]);
    }

    /**
     * Haversine distance in metres between two lat/lng coordinates.
     */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000; // Earth radius in metres
        $φ1 = deg2rad($lat1);
        $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lng2 - $lng1);
        $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
