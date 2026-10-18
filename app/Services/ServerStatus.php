<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Momentopname van de server voor admin → Server.
 *
 * Alles wat hier gemeten wordt kan de webgebruiker (php-fpm, user `milmap`) zonder
 * sudo lezen: /proc, systemctl is-active, de back-upmap, de database en een HTTP-
 * check van de eigen domeinen. Elke sectie is afgeschermd: een kapotte meting
 * levert 'onbekend' op en nooit een 500 voor de hele pagina.
 */
class ServerStatus
{
    private const CACHE_KEY = 'server_status.snapshot';
    private const TTL = 60;

    /** Schrijft een hartslag (queue-worker of scheduler) weg. */
    public static function beat(string $name): void
    {
        Cache::put('server_status.beat.'.$name, now()->timestamp, 86400);
    }

    public static function snapshot(bool $fresh = false): array
    {
        if (! $fresh && is_array($cached = Cache::get(self::CACHE_KEY))) {
            return $cached;
        }

        $checks = [];
        $data = [
            'generated_at' => now()->toIso8601String(),
            'host'         => self::guard(fn () => gethostname() ?: null),
            'system'       => self::guard(function () use (&$checks) { return self::system($checks); }),
            'services'     => self::guard(function () use (&$checks) { return self::services($checks); }) ?? [],
            'domains'      => self::guard(function () use (&$checks) { return self::domains($checks); }) ?? [],
            'backups'      => self::guard(function () use (&$checks) { return self::backups($checks); }),
            'database'     => self::guard(function () use (&$checks) { return self::database($checks); }),
            'queue'        => self::guard(function () use (&$checks) { return self::queue($checks); }),
            'app'          => self::guard(function () use (&$checks) { return self::app($checks); }),
            'rate_limits'  => self::guard(fn () => self::rateLimits()),
        ];

        $statuses = array_column($checks, 'status');
        $data['status'] = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');
        $data['checks'] = $checks;

        Cache::put(self::CACHE_KEY, $data, self::TTL);

        return $data;
    }

    // ── Secties ──────────────────────────────────────────────────────

    private static function system(array &$checks): array
    {
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null;
        $cores = is_readable('/proc/cpuinfo')
            ? (preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')) ?: null)
            : null;

        $mem = [];
        if (is_readable('/proc/meminfo')) {
            foreach (explode("\n", (string) file_get_contents('/proc/meminfo')) as $line) {
                if (preg_match('/^(\w+):\s+(\d+) kB/', $line, $m)) {
                    $mem[$m[1]] = (int) $m[2] * 1024;
                }
            }
        }
        $memTotal = $mem['MemTotal'] ?? null;
        $memAvail = $mem['MemAvailable'] ?? null;
        $swapTotal = $mem['SwapTotal'] ?? null;
        $swapUsed = isset($mem['SwapTotal'], $mem['SwapFree']) ? $mem['SwapTotal'] - $mem['SwapFree'] : null;

        $uptime = is_readable('/proc/uptime') ? (int) explode(' ', (string) file_get_contents('/proc/uptime'))[0] : null;

        $diskTotal = @disk_total_space('/') ?: null;
        $diskFree = @disk_free_space('/') ?: null;
        $diskUsedPct = $diskTotal ? round(($diskTotal - $diskFree) / $diskTotal * 100, 1) : null;
        $memUsedPct = ($memTotal && $memAvail !== null) ? round(($memTotal - $memAvail) / $memTotal * 100, 1) : null;

        if ($load && $cores) {
            $perCore = $load[1] / $cores;
            $checks[] = self::check('cpu', 'Processorbelasting', $perCore > 3 ? 'fail' : ($perCore > 1.5 ? 'warn' : 'ok'),
                sprintf('%.2f over 5 min op %d kernen', $load[1], $cores));
        }
        if ($memUsedPct !== null) {
            $checks[] = self::check('memory', 'Geheugen', $memUsedPct > 95 ? 'fail' : ($memUsedPct > 90 ? 'warn' : 'ok'),
                'Beschikbaar: '.self::bytes($memAvail).' van '.self::bytes($memTotal));
        }
        if ($diskUsedPct !== null) {
            $checks[] = self::check('disk', 'Schijfruimte', $diskUsedPct > 90 ? 'fail' : ($diskUsedPct > 80 ? 'warn' : 'ok'),
                $diskUsedPct.'% vol, '.self::bytes($diskFree).' vrij');
        }

        return [
            'load'          => $load ? array_map(fn ($v) => round($v, 2), $load) : null,
            'cores'         => $cores,
            'mem_total'     => $memTotal,
            'mem_available' => $memAvail,
            'mem_used_pct'  => $memUsedPct,
            'swap_total'    => $swapTotal,
            'swap_used'     => $swapUsed,
            'uptime'        => $uptime,
            'disk_total'    => $diskTotal,
            'disk_free'     => $diskFree,
            'disk_used_pct' => $diskUsedPct,
        ];
    }

    private static function services(array &$checks): array
    {
        $names = (array) config('server_status.services', []);
        if (! $names) {
            return [];
        }

        $bin = is_executable('/usr/bin/systemctl') ? '/usr/bin/systemctl' : 'systemctl';
        $process = new Process([$bin, 'is-active', ...$names]);
        $process->setTimeout(5);
        $process->run(); // exitcode 3 zodra één service niet actief is: dat is geen fout.
        $output = trim($process->getOutput());
        if ($output === '') {
            return []; // geen systemd (of niet toegestaan): "niet te lezen" i.p.v. een valse storing
        }
        $states = preg_split('/\R/', $output) ?: [];

        $out = [];
        foreach ($names as $i => $name) {
            $state = trim($states[$i] ?? '') ?: 'unknown';
            $out[] = ['name' => $name, 'state' => $state, 'active' => $state === 'active'];
            if ($state !== 'active') {
                $checks[] = self::check('service.'.$name, 'Service '.$name, $state === 'unknown' ? 'warn' : 'fail', 'Status: '.$state);
            }
        }

        return $out;
    }

    private static function domains(array &$checks): array
    {
        $domains = (array) config('server_status.domains', []);
        if (! $domains) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($domains) {
            return collect($domains)->map(fn ($d) => $pool->as($d['name'])
                ->connectTimeout(4)->timeout(8)->withHeaders(['User-Agent' => 'MilMap-ServerStatus/1.0'])->get($d['url']))->all();
        });

        $out = [];
        foreach ($domains as $d) {
            $res = $responses[$d['name']] ?? null;
            $ok = false;
            $status = null;
            $ms = null;
            $error = null;
            if ($res instanceof ConnectionException || $res instanceof Throwable) {
                $error = 'Geen verbinding';
            } elseif ($res) {
                $status = $res->status();
                $ms = (int) round(((float) ($res->handlerStats()['total_time'] ?? 0)) * 1000);
                $ok = $status < 400;
            }
            $ssl = self::sslDaysLeft(parse_url($d['url'], PHP_URL_HOST));

            $out[] = ['name' => $d['name'], 'url' => $d['url'], 'ok' => $ok, 'status' => $status, 'ms' => $ms, 'error' => $error, 'ssl_days' => $ssl];

            if (! $ok) {
                $checks[] = self::check('domain.'.$d['name'], $d['name'], 'fail', $error ?: 'HTTP '.$status);
            } elseif ($ms !== null && $ms > 3000) {
                $checks[] = self::check('domain.'.$d['name'], $d['name'], 'warn', 'Traag: '.$ms.' ms');
            }
            if ($ssl !== null && $ssl < 21) {
                $checks[] = self::check('ssl.'.$d['name'], 'SSL '.$d['name'], $ssl < 7 ? 'fail' : 'warn', $ssl >= 0 ? 'Verloopt over '.$ssl.' dagen' : 'Certificaat is verlopen');
            }
        }

        return $out;
    }

    private static function sslDaysLeft(?string $host): ?int
    {
        if (! $host) {
            return null;
        }

        // Certificaten wijzigen zelden: 6 uur cachen scheelt acht TLS-handshakes per verversing.
        return Cache::remember('server_status.ssl.'.$host, 21600, function () use ($host) {
            try {
                $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
                $fp = @stream_socket_client('ssl://'.$host.':443', $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx);
                if (! $fp) {
                    return null;
                }
                $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
                fclose($fp);
                $info = $cert ? openssl_x509_parse($cert) : null;

                return isset($info['validTo_time_t']) ? (int) floor(($info['validTo_time_t'] - time()) / 86400) : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    private static function backups(array &$checks): array
    {
        $dir = (string) config('server_status.backup_path');
        $keep = (int) config('server_status.backup_keep', 2);
        if (! is_dir($dir)) {
            return ['path' => $dir, 'exists' => false, 'total_bytes' => 0, 'count' => 0, 'types' => [], 'recent' => [], 'keep' => $keep];
        }

        $types = [];
        $files = [];
        $total = 0;
        $emptyDumps = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir.'/'.$name;
            $isDir = is_dir($path);
            $size = $isDir ? 0 : (int) @filesize($path);
            $mtime = (int) @filemtime($path);
            $type = preg_match('/^(frontend|backend|site|admin|connect|db|partners|legal)_/', $name, $m) ? $m[1] : 'overig';

            $total += $size;
            $types[$type] ??= ['type' => $type, 'count' => 0, 'bytes' => 0, 'newest' => 0];
            $types[$type]['count']++;
            $types[$type]['bytes'] += $size;
            $types[$type]['newest'] = max($types[$type]['newest'], $mtime);
            if ($type === 'db' && $size < 1024) {
                $emptyDumps++;
            }
            $files[] = ['name' => $name, 'bytes' => $size, 'mtime' => $mtime, 'dir' => $isDir];
        }

        usort($files, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        $types = array_values($types);
        usort($types, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        foreach ($types as &$t) {
            $t['newest_at'] = $t['newest'] ? gmdate('c', $t['newest']) : null;
            $t['over_keep'] = $t['type'] !== 'overig' && $t['count'] > $keep;
            unset($t['newest']);
        }
        unset($t);

        // De database is het enige dat niet uit git terug te halen is: laat het zien als die back-up oud is.
        $db = collect($types)->firstWhere('type', 'db');
        $dbAge = ($db && $db['newest_at']) ? (int) floor((time() - strtotime($db['newest_at'])) / 86400) : null;
        if (! $db) {
            $checks[] = self::check('backup.db', 'Database-back-up', 'fail', 'Geen database-back-up gevonden');
        } elseif ($dbAge > 7) {
            $checks[] = self::check('backup.db', 'Database-back-up', 'fail', 'Laatste back-up is '.$dbAge.' dagen oud');
        } elseif ($dbAge > 2) {
            $checks[] = self::check('backup.db', 'Database-back-up', 'warn', 'Laatste back-up is '.$dbAge.' dagen oud');
        }
        if ($emptyDumps) {
            $checks[] = self::check('backup.empty', 'Lege database-dumps', 'warn', $emptyDumps.' dump(s) kleiner dan 1 kB');
        }

        return [
            'path'        => $dir,
            'exists'      => true,
            'keep'        => $keep,
            'total_bytes' => $total,
            'count'       => count($files),
            'types'       => $types,
            'db_age_days' => $dbAge,
            'empty_dumps' => $emptyDumps,
            'recent'      => array_map(fn ($f) => ['name' => $f['name'], 'bytes' => $f['bytes'], 'at' => gmdate('c', $f['mtime']), 'dir' => $f['dir']], array_slice($files, 0, 8)),
        ];
    }

    private static function database(array &$checks): array
    {
        $t = microtime(true);
        DB::select('select 1');
        $ms = (int) round((microtime(true) - $t) * 1000);

        $out = ['ms' => $ms, 'name' => DB::getDatabaseName(), 'size_bytes' => null, 'threads' => null, 'max_connections' => null, 'uptime' => null, 'slow_queries' => null];

        $out['size_bytes'] = self::guard(fn () => (int) DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())->sum(DB::raw('data_length + index_length')));

        self::guard(function () use (&$out) {
            foreach (DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Uptime','Slow_queries')") as $row) {
                match ($row->Variable_name) {
                    'Threads_connected' => $out['threads'] = (int) $row->Value,
                    'Uptime' => $out['uptime'] = (int) $row->Value,
                    'Slow_queries' => $out['slow_queries'] = (int) $row->Value,
                    default => null,
                };
            }
            $max = DB::select("SHOW VARIABLES LIKE 'max_connections'");
            $out['max_connections'] = isset($max[0]) ? (int) $max[0]->Value : null;
        });

        $checks[] = self::check('database', 'Database', $ms > 1000 ? 'fail' : ($ms > 250 ? 'warn' : 'ok'), 'Antwoordt in '.$ms.' ms');
        if ($out['threads'] && $out['max_connections'] && $out['threads'] / $out['max_connections'] > 0.8) {
            $checks[] = self::check('database.connections', 'Databaseverbindingen', 'warn', $out['threads'].' van '.$out['max_connections'].' in gebruik');
        }

        return $out;
    }

    private static function queue(array &$checks): array
    {
        $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $lastFailed = ($failed && Schema::hasTable('failed_jobs')) ? DB::table('failed_jobs')->max('failed_at') : null;

        $queueBeat = Cache::get('server_status.beat.queue');
        $schedBeat = Cache::get('server_status.beat.scheduler');
        $queueAge = $queueBeat ? time() - (int) $queueBeat : null;
        $schedAge = $schedBeat ? time() - (int) $schedBeat : null;

        // Scheduler draait elke minuut, de queue-hartslag elke 5 minuten (zie routes/console.php).
        $checks[] = $schedAge === null
            ? self::check('scheduler', 'Scheduler', 'warn', 'Nog geen hartslag ontvangen')
            : self::check('scheduler', 'Scheduler', $schedAge > 300 ? 'fail' : ($schedAge > 150 ? 'warn' : 'ok'), 'Laatste run '.self::ago($schedAge));
        $checks[] = $queueAge === null
            ? self::check('queue', 'Queue-worker', 'warn', 'Nog geen hartslag ontvangen')
            : self::check('queue', 'Queue-worker', $queueAge > 1200 ? 'fail' : ($queueAge > 600 ? 'warn' : 'ok'), 'Laatste job verwerkt '.self::ago($queueAge));
        if ($pending !== null && $pending > 100) {
            $checks[] = self::check('queue.backlog', 'Wachtrij', 'warn', $pending.' jobs wachten');
        }
        if ($lastFailed && strtotime($lastFailed.' UTC') > time() - 86400) {
            $checks[] = self::check('queue.failed', 'Mislukte jobs', 'warn', 'Laatste mislukking: '.$lastFailed);
        }

        return [
            'pending'        => $pending,
            'failed'         => $failed,
            'last_failed_at' => $lastFailed ? gmdate('c', strtotime($lastFailed.' UTC')) : null,
            'queue_beat_age' => $queueAge,
            'scheduler_beat_age' => $schedAge,
        ];
    }

    private static function app(array &$checks): array
    {
        $logBytes = 0;
        foreach (glob(storage_path('logs/*.log')) ?: [] as $f) {
            $logBytes += (int) @filesize($f);
        }

        $commit = self::guard(function () {
            $p = new Process(['git', 'log', '-1', '--format=%h|%s|%cI'], base_path());
            $p->setTimeout(3);
            $p->run();
            [$hash, $subject, $at] = array_pad(explode('|', trim($p->getOutput()), 3), 3, null);

            return $hash ? ['hash' => $hash, 'subject' => $subject, 'at' => $at] : null;
        });

        $debug = (bool) config('app.debug');
        $maintenance = app()->isDownForMaintenance();
        $opcache = function_exists('opcache_get_status') ? (bool) (@opcache_get_status(false)['opcache_enabled'] ?? false) : false;

        if ($debug && app()->environment('production')) {
            $checks[] = self::check('debug', 'APP_DEBUG', 'warn', 'Debugmodus staat aan op productie');
        }
        if ($maintenance) {
            $checks[] = self::check('maintenance', 'Onderhoudsmodus', 'fail', 'De API staat in onderhoudsmodus');
        }
        if ($logBytes > 200 * 1048576) {
            $checks[] = self::check('logs', 'Logbestanden', 'warn', self::bytes($logBytes).' aan logs');
        }

        return [
            'laravel'     => app()->version(),
            'php'         => PHP_VERSION,
            'env'         => app()->environment(),
            'debug'       => $debug,
            'maintenance' => $maintenance,
            'opcache'     => $opcache,
            'memory_limit' => ini_get('memory_limit'),
            'log_bytes'   => $logBytes,
            'commit'      => $commit,
            'cache'       => config('cache.default'),
            'queue_driver' => config('queue.default'),
        ];
    }

    private static function rateLimits(): ?array
    {
        if (! Schema::hasTable('rate_limit_events')) {
            return null;
        }

        $q = fn (int $hours) => DB::table('rate_limit_events')->where('last_seen_at', '>=', now()->subHours($hours));
        $day = $q(24)->selectRaw('COALESCE(SUM(hits),0) as hits, COUNT(DISTINCT user_id) as users, COUNT(DISTINCT ip) as ips')->first();
        $week = $q(168)->selectRaw('COALESCE(SUM(hits),0) as hits')->first();

        $top = $q(24)->groupBy('method', 'route', 'provider')
            ->selectRaw('method, route, provider, SUM(hits) as hits, MAX(reason) as reason')
            ->orderByDesc('hits')->limit(5)->get()
            ->map(fn ($r) => ['what' => trim(($r->method ? $r->method.' ' : '').($r->route ?: $r->provider)), 'hits' => (int) $r->hits, 'reason' => $r->reason]);

        return [
            'hits_24h'  => (int) $day->hits,
            'users_24h' => (int) $day->users,
            'ips_24h'   => (int) $day->ips,
            'hits_7d'   => (int) $week->hits,
            'top'       => $top,
        ];
    }

    // ── Hulpjes ──────────────────────────────────────────────────────

    private static function check(string $key, string $label, string $status, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message];
    }

    private static function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function bytes(int|float|null $b): string
    {
        if ($b === null) {
            return '—';
        }
        foreach (['B', 'kB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($b < 1024 || $unit === 'TB') {
                return ($i === 0 ? $b : number_format($b, $b < 10 * 1024 ** $i ? 1 : 0, ',', '.')).' '.$unit;
            }
            $b /= 1024;
        }

        return (string) $b;
    }

    private static function ago(int $seconds): string
    {
        return $seconds < 90 ? $seconds.' sec geleden' : ($seconds < 5400 ? round($seconds / 60).' min geleden' : round($seconds / 3600).' uur geleden');
    }
}
