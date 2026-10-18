<?php

namespace App\Http\Controllers;

use App\Services\ApiUsageMeter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cachende proxy voor publieke OSM-diensten (Nominatim, Overpass) én voor
 * Mapbox' Geocoding API (forward search).
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
 *  - fair-use-limieten van de publieke instances worden veel later geraakt;
 *  - `search()` cachet bovendien de betaalde Mapbox-geocoding-requests: een
 *    veelgetypte plaatsnaam ("Amsterdam") wordt maar één keer per cache-venster
 *    echt bij Mapbox gehaald in plaats van bij elke gebruiker opnieuw.
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
     * Reverse geocoding: coördinaat → plaatsnaam. Naast de samengestelde
     * `place` (voor callers die maar één label willen) gaan ook de losse
     * onderdelen (city/region/country) mee, voor plekken in de app die ze
     * apart tonen (bv. TerrainInfo).
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

        $leeg = ['place' => null, 'city' => null, 'region' => null, 'country' => null, 'countryCode' => null];

        // API-verbruik: upstream-call telt in de closure, anders een cache-hit.
        $upstream = false;
        $resultaat = Cache::remember($sleutel, now()->addDays(30), function () use ($lat, $lon, $lang, $leeg, &$upstream) {
            $upstream = true;
            try {
                $res = ApiUsageMeter::measure('nominatim', 'reverse', fn () => Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(6)
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'lat' => $lat, 'lon' => $lon,
                        'format' => 'json', 'accept-language' => $lang,
                    ]));

                if (!$res->successful()) return $leeg;
                $adres = $res->json('address') ?? [];

                $plaats = $adres['city'] ?? $adres['town'] ?? $adres['village'] ?? $adres['hamlet'] ?? '';
                $regio  = $adres['state'] ?? $adres['region'] ?? '';
                $land   = $adres['country'] ?? '';
                $code   = isset($adres['country_code']) ? strtoupper($adres['country_code']) : '';

                return [
                    'place'       => implode(', ', array_filter([$plaats, $code])) ?: null,
                    'city'        => $plaats ?: null,
                    'region'      => $regio ?: null,
                    'country'     => $land ?: null,
                    'countryCode' => $code ?: null,
                ];
            } catch (\Throwable $e) {
                Log::warning('[geo-proxy] reverse mislukt: ' . $e->getMessage());
                return $leeg;
            }
        });
        if (!$upstream) ApiUsageMeter::record('nominatim', 'reverse', ['cache_hits' => 1]);

        return response()->json($resultaat);
    }

    /**
     * GET /api/v1/geo/search?q=..&limit=5&lang=nl&autocomplete=true&country=nl,be&proximity=lon,lat
     *
     * Forward geocoding (plaatsen zoeken) via Mapbox — hier geproxyd i.p.v.
     * rechtstreeks vanuit de app, zodat een veelgetypte zoekterm niet bij elke
     * gebruiker opnieuw wordt gefactureerd. Geeft de ruwe Mapbox-response door
     * (dezelfde `features[].{id,text,place_name,center,...}`-vorm), zodat de
     * bestaande frontend-parsing ongewijzigd blijft.
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'q'            => ['required', 'string', 'max:200'],
            'limit'        => ['nullable', 'integer', 'min:1', 'max:10'],
            'lang'         => ['nullable', 'string', 'max:8'],
            'autocomplete' => ['nullable', 'string', 'max:5'],
            'country'      => ['nullable', 'string', 'max:200'],
            'proximity'    => ['nullable', 'string', 'max:64'],
        ]);

        $q            = trim($data['q']);
        $limit        = $data['limit'] ?? 5;
        $lang         = $data['lang'] ?? 'nl';
        $autocomplete = $data['autocomplete'] ?? null;
        $country      = $data['country'] ?? null;
        $proximity    = $data['proximity'] ?? null;

        $sleutel = 'geo:search:' . sha1(implode('|', [
            mb_strtolower($q), $limit, $lang, $autocomplete, $country, $proximity,
        ]));

        $upstream = false;
        $resultaat = Cache::remember($sleutel, now()->addDays(7), function () use ($q, $limit, $lang, $autocomplete, $country, $proximity, &$upstream) {
            $upstream = true;
            try {
                $params = array_filter([
                    'access_token' => config('services.mapbox.token'),
                    'limit'        => $limit,
                    'language'     => $lang,
                    'autocomplete' => $autocomplete,
                    'country'      => $country,
                    'proximity'    => $proximity,
                ], fn ($v) => $v !== null && $v !== '');

                $res = ApiUsageMeter::measure('mapbox', 'geocoding', fn () => Http::timeout(6)->get(
                    'https://api.mapbox.com/geocoding/v5/mapbox.places/' . rawurlencode($q) . '.json',
                    $params
                ));

                return $res->successful() ? $res->json() : ['features' => []];
            } catch (\Throwable $e) {
                Log::warning('[geo-proxy] search mislukt: ' . $e->getMessage());
                return ['features' => []];
            }
        });
        if (!$upstream) ApiUsageMeter::record('mapbox', 'geocoding', ['cache_hits' => 1]);

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

        // Alleen GESLAAGDE antwoorden cachen. Een time-out of storing mag geen 12 uur lang als
        // "geen wegen hier" blijven staan: de padenmotor van Kompas Opdracht zou er stil
        // verkeerde routes mee maken. Een mislukking krijgt `error` mee (HTTP 200 blijft, zodat
        // aanroepers die alleen `elements` lezen niet breken).
        $resultaat = Cache::get($sleutel);
        if ($resultaat === null) {
            $resultaat = $this->overpassUpstream($data['query']);
            if (empty($resultaat['error'])) {
                // OSM-wegen en terreinkenmerken veranderen langzaam, en de publieke Overpass is vaak traag
                // (tot ±30 s voor een klein gebied): één keer per week per gebied is genoeg.
                Cache::put($sleutel, $resultaat, now()->addDays(7));
            }
        } else {
            ApiUsageMeter::record('overpass', 'query', ['cache_hits' => 1]);
        }

        return response()->json($resultaat);
    }

    /** Publieke Overpass-instances, in volgorde van voorkeur. Een tweede dient als vangnet: ze zijn vaak druk. */
    private const OVERPASS_ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    /** @return array{elements: array, error?: string} */
    private function overpassUpstream(string $query): array
    {
        // Totaalbudget onder de 60 s van nginx: de eerste instantie mag lang duren (Overpass is
        // soms druk), het vangnet krijgt wat er over is.
        $start = microtime(true);
        foreach (self::OVERPASS_ENDPOINTS as $endpoint) {
            $over = 55 - (microtime(true) - $start);
            if ($over < 5) {
                break;
            }
            try {
                $res = ApiUsageMeter::measure('overpass', 'query', fn () => Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout((int) min(40, $over))
                    ->asForm()
                    ->post($endpoint, ['data' => $query]));

                $json = $res->successful() ? $res->json() : null;
                // Overpass meldt een afgebroken query als HTTP 200 met een `remark`.
                $aborted = is_array($json) && isset($json['remark']) && str_contains((string) $json['remark'], 'runtime error');
                if (is_array($json) && ! $aborted) {
                    return $json;
                }
            } catch (\Throwable $e) {
                Log::warning('[geo-proxy] overpass mislukt (' . parse_url($endpoint, PHP_URL_HOST) . '): ' . $e->getMessage());
            }
        }

        return ['elements' => [], 'error' => 'upstream'];
    }
}
