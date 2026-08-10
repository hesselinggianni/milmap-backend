<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

/** Gekoppeld Garmin-account van de ingelogde gebruiker tonen/ontkoppelen. */
class GarminAccountController extends Controller
{
    /** GET /api/v1/garmin/account */
    public function show()
    {
        $account = Auth::user()->garminAccount;

        if (! $account) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected' => true,
            'garmin_user_id' => $account->garmin_user_id,
            'devices' => $account->devices ?? [],
            'connected_at' => $account->connected_at?->toIso8601String(),
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
        ]);
    }

    /** DELETE /api/v1/garmin/account */
    public function destroy()
    {
        Auth::user()->garminAccount?->delete();

        return response()->json(['message' => 'Garmin-account ontkoppeld']);
    }
}
