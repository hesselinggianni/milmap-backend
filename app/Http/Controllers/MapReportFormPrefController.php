<?php

namespace App\Http\Controllers;

use App\Models\MapReportFormPref;
use App\Services\MapAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Welke meldingsformulieren de ingelogde gebruiker op een kaart gebruikt.
 * Per (gebruiker, kaart); iedereen met toegang tot de kaart — ook een viewer —
 * beheert alleen zijn eigen keuze.
 */
class MapReportFormPrefController extends Controller
{
    /** `hidden` is null zolang de gebruiker voor deze kaart nog niet gekozen heeft. */
    public function show(string $mapId)
    {
        $this->authorizeMap($mapId);

        $pref = MapReportFormPref::where('user_id', Auth::id())
            ->where('map_id', $mapId)
            ->first();

        return response()->json(['hidden' => $pref?->hidden]);
    }

    public function update(Request $request, string $mapId)
    {
        $this->authorizeMap($mapId);

        $data = $request->validate([
            'hidden'   => 'present|array|max:100',
            'hidden.*' => 'string|max:80',
        ]);

        $hidden = array_values(array_unique($data['hidden']));

        MapReportFormPref::updateOrCreate(
            ['user_id' => Auth::id(), 'map_id' => $mapId],
            ['hidden' => $hidden]
        );

        return response()->json(['hidden' => $hidden]);
    }

    private function authorizeMap(string $mapId): void
    {
        abort_unless(
            app(MapAccess::class)->accessibleQuery(Auth::user())->where('maps.id', $mapId)->exists(),
            404
        );
    }
}
