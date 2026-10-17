<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Legt "te veel verzoeken"-meldingen vast voor admin → Te veel verzoeken.
 * Nooit gooiend: loggen mag een 429-antwoord niet in een 500 veranderen.
 */
class RateLimitLog
{
    public const TABLE = 'rate_limit_events';

    /**
     * @param  array{provider?:?string,limiter?:?string,max_attempts?:?int,retry_after?:?int,platform?:?string,user_id?:?int,route?:?string,method?:?string}  $opts
     */
    public static function record(string $source, ?Request $request = null, array $opts = []): void
    {
        try {
            $request ??= request();
            $source = $source === 'external' ? 'external' : 'internal';
            $userId = $opts['user_id'] ?? $request->user()?->getAuthIdentifier();
            $ip = $request->ip();
            $route = $opts['route'] ?? ($source === 'internal' ? ($request->route()?->uri() ?? $request->path()) : null);
            $method = $opts['method'] ?? ($source === 'internal' ? $request->method() : null);
            $provider = $opts['provider'] ?? null;
            $hour = gmdate('YmdH');

            $bucket = substr(sha1(implode('|', [$hour, $source, $provider, $method, $route, $userId, $ip])), 0, 40);
            $now = now();

            $updated = DB::table(self::TABLE)->where('bucket', $bucket)->update([
                'hits'         => DB::raw('hits + 1'),
                'last_seen_at' => $now,
                'retry_after'  => $opts['retry_after'] ?? null,
            ]);
            if ($updated) {
                return;
            }

            DB::table(self::TABLE)->insertOrIgnore([
                'bucket'        => $bucket,
                'source'        => $source,
                'provider'      => $provider ? substr($provider, 0, 40) : null,
                'method'        => $method,
                'route'         => $route ? substr($route, 0, 255) : null,
                'limiter'       => $opts['limiter'] ?? null,
                'user_id'       => $userId ?: null,
                'ip'            => $ip,
                'installation'  => \App\Support\ClientKey::installation($request),
                'platform'      => isset($opts['platform']) ? substr($opts['platform'], 0, 20) : null,
                'user_agent'    => substr((string) $request->userAgent(), 0, 255) ?: null,
                'max_attempts'  => $opts['max_attempts'] ?? null,
                'retry_after'   => $opts['retry_after'] ?? null,
                'hits'          => 1,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
