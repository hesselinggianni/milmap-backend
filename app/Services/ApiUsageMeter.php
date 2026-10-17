<?php

namespace App\Services;

use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use WeakReference;

/**
 * Telt verzoeken naar derde partijen (Mapbox, Nominatim, Overpass, Open-Meteo,
 * ip-api, …) in de tabel api_usage_hourly: per UTC-uur × provider × kind ×
 * bron × gebruiker × netwerk, met atomaire increments.
 *
 *   ApiUsageMeter::record('nominatim', 'reverse', ['cache_hits' => 1]);
 *   $res = ApiUsageMeter::measure('osrm', 'route', fn () => Http::get($url));
 *
 * Uitgangspunten:
 *  - NOOIT een exception het verzoek in laten lopen: alles zit in try/catch en
 *    fouten gaan (gethrottled) naar report().
 *  - Goedkoop: binnen een HTTP-verzoek wordt alleen in het geheugen opgeteld en
 *    pas ná het versturen van de respons (app()->terminating) in één upsert
 *    weggeschreven. Buiten een verzoek (artisan, queue-worker) direct.
 *  - Privacy: geen IP-adressen; het netwerk is de eerste 16 hex van
 *    HMAC-SHA256(ip, app.key) — wel groepeerbaar (school-NAT), niet herleidbaar.
 *
 * Conventie: `requests` = alle verzoeken die wij voor de dienst afhandelden;
 * een cache-hit telt als requests+1 én cache_hits+1, een echte upstream-call
 * als requests+1 (en errors+1 bij non-2xx/exception). Upstream = requests − cache_hits.
 */
class ApiUsageMeter
{
    public const TABLE = 'api_usage_hourly';

    /** Kolommen van de unique key (volgorde = conflict-target van de upsert). */
    public const KEY = ['bucket', 'provider', 'kind', 'source', 'user_id', 'network_hash'];

    /** @var array<string, array<string, mixed>> Opgetelde rijen van dit verzoek. */
    private static array $buffer = [];

    /** App-instantie waarvoor al een terminating-flush is ingepland. */
    private static ?WeakReference $scheduledFor = null;

    /**
     * Eén meting optellen. $opts: requests (1), errors (0), cache_hits (0),
     * user_id (huidige Sanctum-gebruiker of 0), network_hash (van het huidige
     * verzoek), source ('server').
     */
    public static function record(string $provider, string $kind, array $opts = []): void
    {
        try {
            $requests  = max(0, (int) ($opts['requests'] ?? 1));
            $errors    = max(0, (int) ($opts['errors'] ?? 0));
            $cacheHits = max(0, (int) ($opts['cache_hits'] ?? 0));
            if ($requests === 0 && $errors === 0 && $cacheHits === 0) {
                return;
            }

            $row = [
                'bucket'       => self::bucket(),
                'provider'     => self::provider($provider),
                'kind'         => self::kind($kind),
                'source'       => ($opts['source'] ?? 'server') === 'client' ? 'client' : 'server',
                'user_id'      => array_key_exists('user_id', $opts) ? max(0, (int) $opts['user_id']) : self::currentUserId(),
                'network_hash' => array_key_exists('network_hash', $opts)
                    ? substr((string) $opts['network_hash'], 0, 16)
                    : self::networkHash(self::currentIp()),
            ];

            $key = implode('|', $row);
            if (isset(self::$buffer[$key])) {
                self::$buffer[$key]['requests']   += $requests;
                self::$buffer[$key]['errors']     += $errors;
                self::$buffer[$key]['cache_hits'] += $cacheHits;
            } else {
                self::$buffer[$key] = $row + ['requests' => $requests, 'errors' => $errors, 'cache_hits' => $cacheHits];
            }

            self::scheduleFlush();
        } catch (Throwable $e) {
            self::reportQuietly($e);
        }
    }

    /**
     * Voer een uitgaande call uit en tel 'm: requests+1, errors+1 bij een
     * exception of een niet-2xx Http-respons. De exception van de call zelf
     * wordt ongewijzigd doorgegooid — het gedrag van de aanroeper verandert niet.
     * $opts['ok'] (callable(Response): bool) overschrijft wat "geslaagd" is,
     * bv. een 3xx bij het uitklappen van redirects.
     *
     * @template T
     * @param  callable(): T  $call
     * @return T
     */
    public static function measure(string $provider, string $kind, callable $call, array $opts = []): mixed
    {
        $ok = $opts['ok'] ?? null;
        unset($opts['ok']);

        try {
            $result = $call();
        } catch (Throwable $e) {
            self::record($provider, $kind, ['errors' => 1] + $opts);
            throw $e;
        }

        try {
            $failed = $result instanceof HttpResponse && ! ($ok ? $ok($result) : $result->successful());
        } catch (Throwable) {
            $failed = false;
        }
        self::record($provider, $kind, ['errors' => $failed ? 1 : 0] + $opts);

        // Een derde partij die ons afknijpt: ook zichtbaar in admin → Te veel verzoeken.
        if ($result instanceof HttpResponse && $result->status() === 429) {
            RateLimitLog::record('external', null, [
                'provider'    => self::provider($provider),
                'route'       => self::kind($kind),
                'retry_after' => (int) $result->header('Retry-After') ?: null,
            ]);
        }

        return $result;
    }

    /** Gebufferde tellers nu wegschrijven (ook bruikbaar in tests/commands). */
    public static function flush(): void
    {
        $rows = array_values(self::$buffer);
        self::$buffer = [];
        if (! $rows) {
            return;
        }

        try {
            self::write($rows);
        } catch (Throwable $e) {
            self::reportQuietly($e);
        }
    }

    /** Buffer weggooien zonder te schrijven (tests). */
    public static function discard(): void
    {
        self::$buffer = [];
        self::$scheduledFor = null;
    }

    /**
     * Atomair optellen: INSERT … ON DUPLICATE KEY UPDATE requests = requests + VALUES(requests)
     * (MySQL/MariaDB) of ON CONFLICT … DO UPDATE (SQLite/Postgres, o.a. de tests).
     * Rijen moeten alle KEY-kolommen plus requests/errors/cache_hits bevatten.
     * Gooit door bij een DB-fout — gebruik record()/flush() voor de veilige weg.
     */
    public static function write(array $rows): void
    {
        if (! $rows) {
            return;
        }

        $connection = DB::connection();
        $driver     = $connection->getDriverName();
        $alias      = (bool) $connection->getConfig('use_upsert_alias');

        $increment = function (string $column) use ($driver, $alias) {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $incoming = $alias ? "`laravel_upsert_alias`.`{$column}`" : "VALUES(`{$column}`)";
                return DB::raw("`{$column}` + {$incoming}");
            }
            return DB::raw("\"{$column}\" + excluded.\"{$column}\"");
        };

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table(self::TABLE)->upsert($chunk, self::KEY, [
                'requests'   => $increment('requests'),
                'errors'     => $increment('errors'),
                'cache_hits' => $increment('cache_hits'),
            ]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Begin van het huidige UTC-uur. */
    public static function bucket(): string
    {
        return gmdate('Y-m-d H:00:00');
    }

    /** Onbekende provider-sleutels vallen terug op 'other'. */
    public static function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return array_key_exists($provider, (array) config('api_usage.providers', [])) ? $provider : 'other';
    }

    public static function kind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return preg_match('/^[a-z0-9_]{1,40}$/', $kind) ? $kind : 'other';
    }

    /** Eerste 16 hex van HMAC-SHA256(ip, app.key); '' als er geen IP is. */
    public static function networkHash(?string $ip): string
    {
        if (! $ip) {
            return '';
        }

        return substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 16);
    }

    private static function currentIp(): ?string
    {
        try {
            // Artisan/queue-worker: de "request" is een nep-verzoek van 127.0.0.1.
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return null;
            }

            return app()->bound('request') ? request()->ip() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ingelogde gebruiker via Sanctum (bearer-token), anders 0 (gast). De guard
     * cachet de gebruiker per verzoek; EnforceConnectManagement heeft 'm op elk
     * API-verzoek meestal al opgezocht, dus dit kost doorgaans geen query.
     */
    private static function currentUserId(): int
    {
        try {
            if (! app()->bound('request')) {
                return 0;
            }
            $request = request();
            if (! $request->bearerToken() && ! auth('sanctum')->hasUser()) {
                return 0;
            }

            return (int) (auth('sanctum')->user()?->getAuthIdentifier() ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function scheduleFlush(): void
    {
        $app = app();

        // Geen HTTP-verzoek (artisan-command, queue-worker): er volgt geen
        // terminate die ons flusht — meteen wegschrijven.
        if ($app->runningInConsole() && ! $app->runningUnitTests()) {
            self::flush();
            return;
        }

        if (self::$scheduledFor?->get() === $app) {
            return;
        }
        self::$scheduledFor = WeakReference::create($app);

        // Ná het versturen van de respons (PHP-FPM: fastcgi_finish_request),
        // dus de gebruiker merkt niets van de schrijfactie.
        $app->terminating(function () {
            self::$scheduledFor = null;
            self::flush();
        });
    }

    /**
     * report() hooguit eens per 10 minuten: een ontbrekende tabel (deploy vóór
     * migratie) mag de logs niet bij elke tegel vol schrijven.
     */
    public static function reportQuietly(Throwable $e): void
    {
        try {
            if (Cache::add('api-usage-meter:reported', 1, 600)) {
                report($e);
            }
        } catch (Throwable) {
            // Zelfs de cache faalt: stil laten — meten mag nooit iets breken.
        }
    }
}
