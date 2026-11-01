<?php

namespace App\Http\Controllers;

use App\Http\Traits\AuthorizesMapAccess;
use App\Models\Map;
use App\Models\MapWaypoint;
use App\Models\Mission;
use App\Models\Report;
use App\Models\RouteMap;
use Illuminate\Support\Facades\Auth;

/**
 * Samen een kaart beheren: wat is er sinds mijn laatste keer veranderd?
 * GET /api/v1/maps/{id}/live
 *
 * Draait bewust op polling (prod heeft geen Reverb, zie
 * memory/prod-server-config-gotchas): de client vraagt elke paar seconden een
 * goedkope "handtekening" per onderdeel op en haalt alleen het onderdeel dat
 * veranderd is opnieuw op via de gewone endpoints. Zo hoeft er geen
 * wijzigingslog of delta-protocol bij en zijn ook verwijderingen en wijzigingen
 * van oudere app-versies zichtbaar.
 *
 * Handtekening = aantal rijen + nieuwste updated_at + hoogste id van de
 * (niet-verwijderde) rijen. Verwijderen verlaagt het aantal, toevoegen en
 * bewerken verschuift updated_at.
 *
 * `shared` zegt of er iemand anders op deze kaart zit; de client pollt een
 * eigen, niet-gedeelde kaart veel rustiger.
 */
class MapLiveController extends Controller
{
    use AuthorizesMapAccess;

    public function show(string $id)
    {
        $map = Map::findOrFail($id);

        if (! $this->authorizeMapAccess($map)) {
            abort(403, 'Geen toegang tot deze kaart.');
        }

        $mapId = $map->getKey();

        return response()->json([
            'shared' => $this->isShared($map),
            'sig'    => [
                'waypoints' => $this->signature(MapWaypoint::query()->where('map_id', $mapId)),
                'reports'   => $this->signature(Report::query()->where('map_id', $mapId)),
                'routemaps' => $this->signature(RouteMap::query()->where('map_id', $mapId)),
            ],
        ]);
    }

    /** Seconden na een schrijfactie waarin de handtekening "heet" blijft (zie signature()). */
    private const HOT_SECONDS = 2;

    private function signature($query): string
    {
        $row = $query->selectRaw('count(*) as c, max(updated_at) as m, max(id) as i')->first();
        $sig = ($row->c ?? 0) . '|' . ($row->m ?? '') . '|' . ($row->i ?? '');

        // updated_at heeft een resolutie van een seconde: twee bewerkingen binnen
        // dezelfde seconde geven dezelfde handtekening, en een poll tússen die
        // twee zou de tweede missen. Zolang de laatste schrijfactie zo vers is,
        // verandert de handtekening daarom bij elke poll; zodra hij rustig is
        // valt hij terug op de vaste waarde, wat nog één keer als verschil telt
        // en dus de eindstand ophaalt.
        if ($row->m && abs(now()->timestamp - strtotime($row->m)) < self::HOT_SECONDS) {
            $sig .= '~' . (int) floor(microtime(true) * 2);
        }

        return $sig;
    }

    /**
     * Eigenaar met accepted medewerkers, of zelf een medewerker/missiedeelnemer.
     * Een kaart kan ook via een missie gedeeld zijn (deelnemers of een gekoppeld
     * team): die mensen hebben geen map_collaborators-rij. Zo'n kaart telt als
     * gedeeld zodra de eigenaar er een missie aan koppelt, want vals "solo"
     * betekent dat de eigenaar wijzigingen pas na 30 s ziet.
     */
    private function isShared(Map $map): bool
    {
        if ((string) $map->owner_id !== (string) Auth::id()) {
            return true;
        }

        if ($map->collaborators()->where('status', 'accepted')->exists()) {
            return true;
        }

        return Mission::query()
            ->where('map_ref_id', (string) $map->getKey())
            ->where('owner_id', $map->owner_id)
            ->exists();
    }
}
