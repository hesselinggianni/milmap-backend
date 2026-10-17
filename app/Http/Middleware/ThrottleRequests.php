<?php

namespace App\Http\Middleware;

use App\Support\ClientKey;
use App\Services\RateLimitLog;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;

/**
 * Vervangt de standaard `throttle`-alias (zie bootstrap/app.php).
 *
 * Laravel telt alle ongenaamde `throttle:N,M`-routes van één client in dezelfde
 * emmer (sleutel = domein|IP). Kaarttegels (600/min), app-events en static-map
 * vulden zo het budget van /login en de Connect-apparaatlogin (10/min): na
 * gewoon kaartgebruik gaf opnieuw inloggen "Too Many Attempts.".
 *
 * Hier krijgt elke route een eigen emmer, en telt een gast per installatie i.p.v.
 * per IP, met een plafond per IP voor NAT-netwerken (zie ClientKey). Named
 * limiters (`throttle:api`, `throttle:connect-device`) gaan ongewijzigd door.
 */
class ThrottleRequests extends BaseThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (is_string($maxAttempts)
            && func_num_args() === 3
            && ! is_null($limiter = $this->limiter->limiter($maxAttempts))) {
            return $this->handleRequestUsingNamedLimiter($request, $next, $maxAttempts, $limiter);
        }

        $max = $this->resolveMaxAttempts($request, $maxAttempts);
        $decay = (int) (60 * $decayMinutes);
        $route = $prefix.'route:'.$request->method().' '.($request->route()?->uri() ?? $request->path());

        if ($user = $request->user()) {
            $limits = [$this->limit($route.'|user:'.$user->getAuthIdentifier(), $max, $decay)];
        } else {
            $client = ClientKey::forGuest($request);
            $limits = [$this->limit($route.'|'.$client, $max, $decay)];
            if (! str_starts_with($client, 'ip:')) {
                $limits[] = $this->limit($route.'|ip:'.$request->ip(), $max * ClientKey::natFactor(), $decay);
            }
        }

        return $this->handleRequest($request, $next, $limits);
    }

    private function limit(string $key, int $max, int $decaySeconds): object
    {
        return (object) [
            'key' => sha1($key),
            'maxAttempts' => $max,
            'decaySeconds' => $decaySeconds,
            'responseCallback' => null,
        ];
    }

    /**
     * Zelfde 429 als Laravel (message blijft 'Too Many Attempts.' voor oude
     * clients), plus een korte reden voor de gebruiker, en een logregel voor
     * admin → Te veel verzoeken (wie, vanaf welk IP, welke route).
     */
    protected function buildException($request, $key, $maxAttempts, $responseCallback = null)
    {
        $exception = parent::buildException($request, $key, $maxAttempts, $responseCallback);
        if ($exception instanceof HttpResponseException) {
            return $exception;
        }

        $retryAfter = (int) $this->getTimeUntilNextRetry($key);
        RateLimitLog::record('internal', $request, [
            'max_attempts' => (int) $maxAttempts,
            'retry_after'  => $retryAfter,
            'limiter'      => 'throttle',
            'platform'     => $request->header('X-Milmap-Platform'),
        ]);

        return new HttpResponseException(response()->json([
            'message'     => 'Too Many Attempts.',
            'code'        => 'rate_limited',
            'reason'      => 'Te veel verzoeken in korte tijd.',
            'retry_after' => $retryAfter,
        ], 429, $exception->getHeaders()));
    }
}
