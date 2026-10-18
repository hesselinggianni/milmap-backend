<?php

namespace App\Http\Controllers;

use App\Services\ApiUsageMeter;
use App\Support\TileWebp;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * PDOK/Kadaster-kaarttegels op onze eigen server (cachende proxy + "kloon").
 *
 * De NL-stafkaart in de app (thema "top25") haalde al zijn tegels rechtstreeks
 * bij service.pdok.nl: de BRT-achtergrondkaart (WMTS) als basis en de
 * TOPraster-WMS (top25raster/top50raster) ingezoomd. Nu komt elke tegel maar
 * één keer bij PDOK vandaan, wordt als WebP opgeslagen
 * (storage/app/tiles/pdok/{laag}/{z}/{x}/{y}.webp, zie App\Support\TileWebp)
 * en daarna van onze schijf geserveerd — sneller, kleiner, onafhankelijk van
 * de beschikbaarheid van PDOK en minder belasting van hun dienst.
 * `tiles:warm-pdok` haalt heel Nederland vooraf binnen (config
 * services.pdok.clone); wat daarbuiten valt vult deze proxy lazy. TOP50 is
 * hiervan uitgezonderd: scripts/build-top50.py bouwt een permanente lokale
 * kopie uit de landelijke GeoTIFF-bron. TOP50-verzoeken gaan nooit naar PDOK.
 *
 *   GET /api/v1/tiles/pdok/brt/{z}/{x}/{y}    BRT-achtergrondkaart (WMTS, z0–19)
 *   GET /api/v1/tiles/pdok/top25/{z}/{x}/{y}  TOPraster top25raster (WMS → XYZ, z13–16)
 *   GET /api/v1/tiles/pdok/top50/{z}/{x}/{y}  Lokale TOP50raster WebP-piramide, z0–14
 *
 * Licentie: BRT Achtergrondkaart én TOPraster zijn CC-BY 4.0 (pdok.nl/copyright):
 * kopiëren, verspreiden en commercieel gebruik mogen, naamsvermelding Kadaster
 * blijft verplicht (staat in de attributions van de kaartlagen in de app).
 * PDOK vraagt bij serverside calls een Referer-header mee te sturen (PDC 5.2) en
 * geen excessief gebruik — vandaar de cache, de per-tegel-lock en de begrenzing
 * tot het NL-dekkingsgebied. De BRT wordt ±5× per jaar bijgewerkt; tegels ouder
 * dan de TTL worden ververst (tiles:prune-pdok ruimt op). Bij een onbereikbare
 * upstream liever een verlopen tegel dan niets.
 *
 * De WMS-zoombereiken volgen uit de schaalgrenzen van de dienst (top25raster
 * 1:4.000–1:50.000, top50raster 1:12.000–1:100.000) op de breedtegraad van NL;
 * daarbuiten levert de WMS een lege tegel, die we niet willen opslaan.
 */
class PdokTileController extends Controller
{
    /** Cache-levensduur in dagen (BRT wordt ±5×/jaar bijgewerkt). */
    public const CACHE_TTL_DAYS = 60;

    /**
     * tiles:warm-pdok ververst tegels ouder dan dit. Bewust ruim onder de TTL: de
     * warm-run staat twee keer per maand (elke ±15 dagen) gepland, dus een tegel
     * mag niet verlopen vóór de run die hem had moeten verversen.
     */
    public const WARM_REFRESH_DAYS = 40;

    /** tiles:prune-pdok wist pas ruim ná de TTL, zodat een verlopen tegel nog als noodgreep dient. */
    public const PRUNE_AFTER_DAYS = 105;

    public const TILE_SIZE = 256;

    /** Ruim NL-dekkingsgebied (lon/lat). Tegels die hier niet in vallen gaan nooit naar PDOK. */
    public const COVERAGE = ['minLon' => 2.0, 'minLat' => 50.0, 'maxLon' => 8.0, 'maxLat' => 54.5];

    public const LAYERS = [
        'brt'   => ['type' => 'wmts', 'minZoom' => 0,  'maxZoom' => 19],
        'top25' => ['type' => 'wms',  'minZoom' => 13, 'maxZoom' => 16, 'layer' => 'top25raster'],
        'top50' => ['type' => 'wms',  'minZoom' => 0, 'maxZoom' => 14, 'layer' => 'top50raster'],
    ];

    /** URL-template uit de WMTS-GetCapabilities (TileMatrixSet EPSG:3857, 00–19). */
    private const WMTS_URL = 'https://service.pdok.nl/kadaster/brt-achtergrondkaart/wmts/v2_0/standaard/EPSG:3857/%d/%d/%d.png';

    private const WMS_URL = 'https://service.pdok.nl/kadaster/brt-topraster/wms/v1_0';

    /**
     * 1×1 volledig transparante PNG. Antwoord voor tegels buiten het
     * dekkingsgebied of zoombereik: PDOK geeft daar zelf ook een volledig
     * transparante tegel, dus OpenLayers/MapLibre én de offline-download
     * gedragen zich identiek — zonder upstream-call of schijfgebruik.
     */
    private const BLANK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function show(string $layer, int $z, int $x, int $y)
    {
        $def = self::LAYERS[$layer] ?? null;
        if (! $def) {
            return response()->json(['message' => 'Unknown layer.'], 404);
        }

        $side = 2 ** $z;
        if ($z > $def['maxZoom'] || $x < 0 || $y < 0 || $x >= $side || $y >= $side) {
            return response()->json(['message' => 'Tile out of range.'], 404);
        }
        if ($z < $def['minZoom'] || ! self::coversTile($z, $x, $y)) {
            return $this->blankResponse();
        }

        $base = self::cacheBase($layer, $z, $x, $y);
        // TOP50 is een permanente lokale kopie. Ook bij een ontbrekende tegel
        // mag een kaartverzoek nooit een externe download starten.
        if ($layer === 'top50') {
            $file = self::cachedFile($base);
            return $file ? $this->tileResponse($file) : $this->blankResponse();
        }
        $miss = "{$base}.404"; // marker: upstream gaf 404, niet blijven hameren

        if ($file = self::freshFile($base)) {
            ApiUsageMeter::record('pdok', 'tiles', ['cache_hits' => 1]);
            return $this->tileResponse($file);
        }
        if (self::fresh($miss, 1)) {
            ApiUsageMeter::record('pdok', 'tiles', ['cache_hits' => 1]);
            return response()->noContent(404);
        }

        // Eén upstream-call per tegel, ook als een hele klas tegelijk dezelfde
        // regio opent: de eerste haalt 'm op, de rest wacht kort en leest de cache.
        $lock = Cache::lock("pdok-tile:{$layer}:{$z}:{$x}:{$y}", 45);

        // block() gooit een exceptie als de lock niet vrijkomt (geen false): vang die af,
        // anders krijgt de client een 500 en blijft de FPM-worker te lang bezet.
        try {
            $acquired = $lock->block(3);
        } catch (LockTimeoutException) {
            $acquired = false;
        }
        if (! $acquired) {
            return $this->staleOrRetry($base, 5);
        }

        try {
            if ($file = self::freshFile($base)) {
                ApiUsageMeter::record('pdok', 'tiles', ['cache_hits' => 1]);
                return $this->tileResponse($file);
            }
            if (self::fresh($miss, 1)) {
                ApiUsageMeter::record('pdok', 'tiles', ['cache_hits' => 1]);
                return response()->noContent(404);
            }

            // Globaal budget voor échte upstream-calls: de throttle op de route telt ook
            // cache-hits en remt dus niets af. PDOK vraagt om "normaal gebruik".
            $budget = (int) config('services.pdok.upstream_per_minute', 1200);
            if (RateLimiter::tooManyAttempts('pdok-upstream', $budget)) {
                return $this->staleOrRetry($base, 15);
            }
            RateLimiter::hit('pdok-upstream', 60);

            try {
                $resp = ApiUsageMeter::measure('pdok', 'tiles', fn () => self::fetchUpstream($layer, $z, $x, $y));
            } catch (Throwable) {
                $stale = self::cachedFile($base);
                return $stale
                    ? $this->tileResponse($stale) // verlopen > niets
                    : response()->noContent(502);
            }

            if (self::isImage($resp)) {
                // Opslaan kan mislukken (schijf vol/bijna vol, schrijffout): de tegel is dan
                // wel opgehaald, dus serveer hem gewoon zonder hem te bewaren.
                $file = null;
                try {
                    $file = self::store($base, $resp->body());
                } catch (Throwable $e) {
                    report($e);
                }

                return $file ? $this->tileResponse($file) : $this->bodyResponse($resp->body());
            }

            if ($resp->successful()) {
                // 2xx zonder afbeelding: de WMS geeft bij een foute request een XML-foutdocument
                // met status 200. Tijdelijk (kan een storing zijn): niet 24 uur onthouden.
                ApiUsageMeter::record('pdok', 'tiles', ['requests' => 0, 'errors' => 1]);
            } elseif ($resp->status() === 404) {
                // Alleen een echte 404 is het onthouden waard (markeer 1 dag, niet blijven hameren).
                @mkdir(dirname($base), 0755, true);
                File::put($miss, '');
                return response()->noContent(404);
            }

            return $this->staleOrRetry($base, 30, 502);
        } finally {
            $lock->release();
        }
    }

    // ── Opslag (gedeeld met tiles:warm-pdok) ─────────────────────────

    /** Pad van de tegel zónder extensie (.webp of .png, zie store()). */
    public static function cacheBase(string $layer, int $z, int $x, int $y): string
    {
        return storage_path("app/tiles/pdok/{$layer}/{$z}/{$x}/{$y}");
    }

    /** Opgeslagen tegelbestand (ongeacht leeftijd), of null. */
    public static function cachedFile(string $base): ?string
    {
        foreach (['webp', 'png'] as $ext) {
            if (is_file("{$base}.{$ext}")) {
                return "{$base}.{$ext}";
            }
        }

        return null;
    }

    /** Opgeslagen tegelbestand dat nog binnen de TTL valt, of null. */
    public static function freshFile(string $base): ?string
    {
        $file = self::cachedFile($base);

        return $file && self::fresh($file, self::CACHE_TTL_DAYS) ? $file : null;
    }

    public static function fresh(string $path, int $days): bool
    {
        return is_file($path) && filemtime($path) > now()->subDays($days)->getTimestamp();
    }

    /**
     * Sla een opgehaalde PNG-tegel op als WebP (of als PNG wanneer er geen
     * encoder is) en ruim de andere variant + de 404-marker op.
     *
     * @return string pad van het opgeslagen bestand
     */
    public static function store(string $base, string $png): ?string
    {
        // Schijf bijna vol: niet bewaren (de tegel wordt wel geserveerd). Voorkomt dat de
        // lazy-fetch de server vol schrijft.
        $minFree = (float) config('services.pdok.min_free_gb', 3) * 1024 ** 3;
        $free = @disk_free_space(storage_path('app'));
        if ($free !== false && $free < $minFree) {
            return null;
        }

        $dir = dirname($base);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (! is_dir($dir)) {
            return null;
        }

        $webp = TileWebp::available() ? TileWebp::encode($png) : null;
        $ext  = $webp !== null ? 'webp' : 'png';
        $data = $webp ?? $png;

        // Atomair: eerst naar een tijdelijk bestand en dan hernoemen, zodat een
        // afgebroken schrijfactie nooit als een "verse" (maar afgekapte) tegel geldt.
        $tmp = "{$base}.{$ext}.tmp" . getmypid();
        if (@file_put_contents($tmp, $data) !== strlen($data) || ! @rename($tmp, "{$base}.{$ext}")) {
            @unlink($tmp);
            return null;
        }
        @unlink("{$base}." . ($ext === 'webp' ? 'png' : 'webp'));
        @unlink("{$base}.404");

        return "{$base}.{$ext}";
    }

    public static function isImage(\Illuminate\Http\Client\Response $resp): bool
    {
        return $resp->successful()
            && str_starts_with((string) $resp->header('Content-Type'), 'image/')
            && $resp->body() !== '';
    }

    // ── Upstream (gedeeld met tiles:warm-pdok) ───────────────────────

    /**
     * Uitgaande request naar PDOK, met de Referer die PDOK van afnemers vraagt
     * (PDC 5.2) en een herkenbare User-Agent.
     */
    public static function upstream(int $timeout = 10): PendingRequest
    {
        return Http::timeout($timeout)
            ->retry(2, 250, throw: false)
            ->withHeaders(self::upstreamHeaders());
    }

    public static function upstreamHeaders(): array
    {
        return [
            'Referer'    => config('services.pdok.referer'),
            'User-Agent' => 'MilMap tile-proxy (+https://milmap.nl)',
        ];
    }

    /** @return array{0: string, 1: array<string, string|int>} upstream-URL + query */
    public static function upstreamRequest(string $layer, int $z, int $x, int $y): array
    {
        $def = self::LAYERS[$layer];

        if ($def['type'] === 'wmts') {
            return [sprintf(self::WMTS_URL, $z, $x, $y), []];
        }

        return [self::WMS_URL, [
            'SERVICE'     => 'WMS',
            'VERSION'     => '1.3.0',
            'REQUEST'     => 'GetMap',
            'LAYERS'      => $def['layer'],
            'STYLES'      => '',
            'CRS'         => 'EPSG:3857',
            'BBOX'        => implode(',', self::tileBboxMercator($z, $x, $y)),
            'WIDTH'       => self::TILE_SIZE,
            'HEIGHT'      => self::TILE_SIZE,
            'FORMAT'      => 'image/png',
            'TRANSPARENT' => 'TRUE',
        ]];
    }

    public static function fetchUpstream(string $layer, int $z, int $x, int $y): \Illuminate\Http\Client\Response
    {
        [$url, $query] = self::upstreamRequest($layer, $z, $x, $y);

        return self::upstream()->get($url, $query);
    }

    // ── Tegelrekenwerk ───────────────────────────────────────────────

    /** Valt (een deel van) de tegel binnen het NL-dekkingsgebied? */
    public static function coversTile(int $z, int $x, int $y): bool
    {
        [$lonMin, $latMin, $lonMax, $latMax] = self::tileBboxDegrees($z, $x, $y);
        $c = self::COVERAGE;

        return $lonMax >= $c['minLon'] && $lonMin <= $c['maxLon']
            && $latMax >= $c['minLat'] && $latMin <= $c['maxLat'];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} [lonMin, latMin, lonMax, latMax] */
    public static function tileBboxDegrees(int $z, int $x, int $y): array
    {
        $n = 2 ** $z;
        $lonMin = $x / $n * 360 - 180;
        $lonMax = ($x + 1) / $n * 360 - 180;
        $lat = fn (int $yTile) => rad2deg(atan(sinh(M_PI * (1 - 2 * $yTile / $n))));

        return [$lonMin, $lat($y + 1), $lonMax, $lat($y)];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} EPSG:3857 [xMin, yMin, xMax, yMax] */
    public static function tileBboxMercator(int $z, int $x, int $y): array
    {
        [$lonMin, $latMin, $lonMax, $latMax] = self::tileBboxDegrees($z, $x, $y);
        $toMerc = fn (float $lon, float $lat) => [
            $lon * 20037508.34 / 180,
            (log(tan((90 + $lat) * M_PI / 360)) / (M_PI / 180)) * 20037508.34 / 180,
        ];
        [$xMin, $yMin] = $toMerc($lonMin, $latMin);
        [$xMax, $yMax] = $toMerc($lonMax, $latMax);

        return [$xMin, $yMin, $xMax, $yMax];
    }

    /** lon/lat → tegelindex (Web Mercator, XYZ). */
    public static function tileIndex(float $lon, float $lat, int $z): array
    {
        $n = 2 ** $z;
        $x = (int) floor(($lon + 180) / 360 * $n);
        $lat = max(-85.0511, min(85.0511, $lat));
        $y = (int) floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n);

        return [max(0, min($n - 1, $x)), max(0, min($n - 1, $y))];
    }

    // ── Responses ────────────────────────────────────────────────────

    private function tileResponse(string $path)
    {
        return response()->file($path, $this->headers(str_ends_with($path, '.webp') ? 'image/webp' : 'image/png'));
    }

    /** Verlopen tegel als noodgreep, anders een nette "probeer zo opnieuw" (i.p.v. een 500). */
    private function staleOrRetry(string $base, int $retryAfter, int $status = 503)
    {
        $stale = self::cachedFile($base);

        return $stale
            ? $this->tileResponse($stale)
            : response()->noContent($status)->header('Retry-After', (string) $retryAfter);
    }

    /** Opgehaalde tegel die niet bewaard kon worden: direct serveren, kort cachen. */
    private function bodyResponse(string $png)
    {
        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=300']);
    }

    private function blankResponse()
    {
        return response(base64_decode(self::BLANK_PNG), 200, $this->headers('image/png'));
    }

    private function headers(string $contentType): array
    {
        return [
            'Content-Type'  => $contentType,
            'Cache-Control' => 'public, max-age=' . (self::CACHE_TTL_DAYS * 86400) . ', immutable',
        ];
    }
}
