<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\GarminService;
use Illuminate\Http\Request;

/**
 * Platform-brede Garmin Connect Developer Program-credentials, DB-configureerbaar
 * met .env-fallback — zelfde patroon als AdminPageSpeedController/PageSpeedService.
 * De secret wordt nooit teruggestuurd, alleen een hasClientSecret-boolean.
 */
class AdminGarminController extends Controller
{
    public function __construct(private GarminService $garmin) {}

    /** GET /api/v1/admin/garmin/config */
    public function config()
    {
        return response()->json([
            'hasClientId' => $this->garmin->clientId() !== null,
            'hasClientSecret' => $this->garmin->clientSecret() !== null,
            'redirectUri' => $this->garmin->redirectUri(),
            'webhookUrl' => rtrim(config('app.url'), '/') . '/api/v1/webhooks/garmin/ping',
        ]);
    }

    /** POST /api/v1/admin/garmin/config */
    public function saveConfig(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'nullable|string|max:255',
            'client_secret' => 'nullable|string|max:255',
        ]);

        if (array_key_exists('client_id', $data) && $data['client_id'] !== null) {
            Setting::put('garmin_client_id', trim($data['client_id']));
        }
        if (array_key_exists('client_secret', $data) && $data['client_secret'] !== null) {
            Setting::put('garmin_client_secret', trim($data['client_secret']));
        }

        return response()->json([
            'hasClientId' => $this->garmin->clientId() !== null,
            'hasClientSecret' => $this->garmin->clientSecret() !== null,
        ]);
    }
}
