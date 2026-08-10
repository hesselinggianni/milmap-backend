<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cachende proxy voor publieke OSM-diensten (Nominatim, Overpass).
 *
 * WAAROM
 * Deze diensten werden rechtstreeks vanuit de app aangeroepen. Daarmee ging bij
 * elke reverse-geocode de POSITIE VAN DE GEBRUIKER — plus zijn IP-adres —
 * rechtstreeks naar een server van een derde partij. Voor een app die zich op
 * defensie/hulpdiensten richt is dat precies het verkeerde signaal, en het
 * levert onnodig extra verwerkers op in de privacyverklaring.
 *
 * Via deze proxy ziet de externe dienst alleen ONZE server. Bijkomend voordeel:
 *  - één cache voor alle gebruikers i.p.v. per toestel opnieuw bevragen;
 *  - we kunnen een correcte User-Agent meesturen. Nominatim EIST die in zijn
 *    usage policy; een kale browser-UA is formeel in overtreding.
 *  - fair-use-limieten van de publieke instances worden veel later geraakt.
 *
 * Zelfde opzet als TerrainTileController (Mapbox-tiles), maar met de
 * cache-driver i.p.v. schijf: de antwoorden zijn klein en kortlevend.
 */
class GeoProxyController extends Controller
{
    /** Nominatim vraagt om een herkenbare UA met contactmogelijkheid. */
    private const USER_AGENT = 'MilMap/1.0 (+https://milmap.nl; support@milmap.nl)';

    /**
     * GET /api/v1/geo/reverse?lat=..&lon=..&lang=nl
     *
     * Reverse geocoding: coördinaat → plaatsnaam. Alleen de velden die de app
     * gebruikt gaan terug (plaats + landcode); de rest van het Nominatim-
     * antwoord bevat adresdetails die we niet nodig hebben en dus ook niet
     * hoeven door te geven.
     */
    public function reverse(Request $request)
    {
        $data = $request->validate([
            'lat'  => ['required', 'numeric', 'between:-90,90'],
            'lon'  => ['required', 'numeric', 'between:-180,180'],
            'lang' => ['nullable', 'string', 'max:8'],
        ]);

        $lat  = round((float) $data['lat'], 4);   // ~11 m: genoeg voor een plaatsnaam
        $lon  = round((float) $data['lon'], 4);   // en het vergroot meteen de cache-trefkans
        $lang = $data['lang'] ?? 'nl';

        $sleutel = "geo:rev:{$lang}:{$lat}:{$lon}";

        $resultaat = Cache::remember($sleutel, now()->addDays(30), function () use ($lat, $lon, $lang) {
            try {
                $res = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(6)
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'lat' => $lat, 'lon' => $lon,
                        'format' => 'json', 'accept-language' => $lang,
                    ]);

                if (!$res->successful()) return ['place' => null];
                $adres = $res->json('address') ?? [];

                $plaats = $adres['city'] ?? $adres['town'] ?? $adres['village'] ?? $adres['hamlet'] ?? '';
                $land   = isset($adres['country_code']) ? strtoupper($adres['country_code']) : '';

                return ['place' => implode(', ', array_filter([$plaats, $land])) ?: null];
            } catch (\Throwable $e) {
                Log::warning('[geo-proxy] reverse mislukt: ' . $e->getMessage());
                return ['place' => null];
            }
        });

        return response()->json($resultaat);
    }

    /**
     * POST /api/v1/geo/overpass  { "query": "..." }
     *
     * Overpass QL doorgeven. De query komt uit de app (terreinanalyse); we
     * cachen op de hash zodat dezelfde vraag niet telkens opnieuw naar de
     * publieke instance gaat.
     */
    public function overpass(Request $request)
    {
        $data = $request->validate([
            // Ruim genoeg voor een terreinquery, maar niet ongelimiteerd: dit
            // endpoint mag geen open doorgeefluik naar Overpass worden.
            'query' => ['required', 'string', 'max:8000'],
        ]);

        $sleutel = 'geo:overpass:' . sha1($data['query']);

        $resultaat = Cache::remember($sleutel, now()->addHours(12), function () use ($data) {
            try {
                $res = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(25) // Overpass mag traag zijn
                    ->asForm()
                    ->post('https://overpass-api.de/api/interpreter', ['data' => $data['query']]);

                return $res->successful() ? $res->json() : ['elements' => []];
            } catch (\Throwable $e) {
                Log::warning('[geo-proxy] overpass mislukt: ' . $e->getMessage());
                return ['elements' => []];
            }
        });

        return response()->json($resultaat);
    }
}
