<?php

namespace App\Http\Controllers;

use App\Jobs\ImportGarminActivity;
use App\Models\GarminAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ontvangt Garmin's "Ping"-notificaties: een lichte melding dat er nieuwe
 * activiteitdata beschikbaar is, GEEN activiteitdata zelf. We vertrouwen de
 * payload dus nooit als brondata — alleen als signaal om ImportGarminActivity
 * te dispatchen, die de echte data zelf ophaalt met het account-token.
 *
 * VERIFY: exacte payload-vorm + of Garmin de request signeert (zie
 * GARMIN_WEBHOOK_SECRET in config/services.php, nog niet gebruikt hieronder
 * omdat de signing-methode niet bevestigd is zonder portaaltoegang).
 */
class GarminWebhookController extends Controller
{
    /** POST /api/v1/webhooks/garmin/ping */
    public function ping(Request $request)
    {
        // VERIFY: exacte top-level sleutel + per-entry veldnamen. Aanname:
        // een array van { userId, callbackURL } per gewijzigde gebruiker,
        // consistent met Garmin's overige Health/Wellness-Ping-API's.
        $entries = $request->input('activities', $request->input('activityDetails', []));

        foreach ((array) $entries as $entry) {
            $garminUserId = (string) ($entry['userId'] ?? '');
            if ($garminUserId === '') {
                continue;
            }

            $account = GarminAccount::where('garmin_user_id', $garminUserId)->first();
            if (! $account) {
                // Onbekende gebruiker: loggen + overslaan, geen 4xx — anders
                // stuurt Garmin dezelfde ping in een retry-storm opnieuw.
                Log::info("Garmin-ping voor onbekende garmin_user_id: {$garminUserId}");
                continue;
            }

            ImportGarminActivity::dispatch($account->id, $entry);
        }

        return response()->json(['received' => true]);
    }
}
