<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * Publieke check voor "er is een nieuwere app-versie in de store" — de native
 * app roept dit bij opstart aan om desgewenst een update-melding te tonen.
 * Waarden komen uit Setting (key 'app_store_versions'), zodat een nieuwe
 * store-versie geen deploy vergt: gewoon Setting::put() bijwerken.
 */
class AppVersionController extends Controller
{
    private const DEFAULTS = [
        'ios' => [
            'latest_version' => '1.10',
            'store_url' => 'https://apps.apple.com/app/id6788657368',
        ],
        'android' => [
            'latest_version' => '1.10',
            'store_url' => 'https://play.google.com/store/apps/details?id=nl.milmap.app',
        ],
    ];

    public function show(): JsonResponse
    {
        $versions = Setting::get('app_store_versions', self::DEFAULTS);

        return response()->json([
            'ios' => $versions['ios'] ?? self::DEFAULTS['ios'],
            'android' => $versions['android'] ?? self::DEFAULTS['android'],
        ]);
    }
}
