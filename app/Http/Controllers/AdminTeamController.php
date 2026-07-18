<?php

namespace App\Http\Controllers;

use App\Models\Team;

/**
 * Admin-overzicht "Teams & abonnementen": read-only weergave van welke
 * gebruikers onder welk team-abonnement vallen (owner + meeliftende leden) +
 * seat-gebruik. Auth: admin.auth-middleware (zie routes/api.php).
 */
class AdminTeamController extends Controller
{
    /** GET /api/v1/admin/teams — alle teams met owner, leden en seat-gebruik. */
    public function index()
    {
        $teams = Team::with([
            'owner:id,first_name,last_name,email',
            'members.user:id,first_name,last_name,email',
        ])->latest()->get();

        $out = $teams->map(function (Team $t) {
            $owner = $t->owner;

            return [
                'id'          => $t->id,
                'name'        => $t->name,
                'owner'       => $owner ? [
                    'id'    => $owner->id,
                    'name'  => $owner->full_name,
                    'email' => $owner->email,
                    'plan'  => $owner->plan(),
                ] : null,
                // Seats zijn owner-breed (gedeelde pool over al zijn teams).
                'seats'       => Team::seatUsageForOwner((int) $t->owner_id),
                'members'     => $t->members->map(fn ($m) => [
                    'id'      => $m->id,
                    'name'    => $m->user?->full_name ?? $m->email,
                    'email'   => $m->email,
                    'user_id' => $m->user_id,
                    'role'    => $m->role,
                    'status'  => $m->status,
                ])->values(),
                'created_at'  => $t->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'teams'                => $out,
            'included_seats'       => (int) config('teams.included_seats', 10),
            'extra_seat_price_eur' => (float) config('teams.extra_seat_price_eur', 2.0),
        ]);
    }
}
