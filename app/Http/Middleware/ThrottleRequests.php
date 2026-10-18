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
    /** Leesbare namen voor de named limiters, voor admin → Te veel verzoeken. */
    private const LIMITER_LABELS = [
        'api'            => 'Algemene API-limiet',
        'connect-device' => 'Connect-apparaatlogin',
    ];

    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (is_string($maxAttempts)
            && func_num_args() === 3
            && ! is_null($limiter = $this->limiter->limiter($maxAttempts))) {
            return $this->handleRequestUsingNamedLimiter($request, $next, $maxAttempts, $limiter);
        }

        // De groep-limiet (throttle:api) draait eerst en zet zijn naam op het request;
        // een route-eigen limiet daarna mag die niet erven.
        $request->attributes->remove('rl_limiter');

        $max = $this->resolveMaxAttempts($request, $maxAttempts);
        $decay = (int) (60 * $decayMinutes);
        $route = $prefix.'route:'.$request->method().' '.($request->route()?->uri() ?? $request->path());

        $scopes = [];
        if ($user = $request->user()) {
            $limits = [$this->limit($route.'|user:'.$user->getAuthIdentifier(), $max, $decay)];
            $scopes[$limits[0]->key] = 'per gebruiker';
        } else {
            $client = ClientKey::forGuest($request);
            $limits = [$this->limit($route.'|'.$client, $max, $decay)];
            $scopes[$limits[0]->key] = str_starts_with($client, 'ip:') ? 'per IP' : 'per apparaat';
            if (! str_starts_with($client, 'ip:')) {
                $limits[] = $this->limit($route.'|ip:'.$request->ip(), $max * ClientKey::natFactor(), $decay);
                $scopes[$limits[1]->key] = 'per IP (gedeeld netwerk)';
            }
        }
        $request->attributes->set('rl_scopes', $scopes);

        return $this->handleRequest($request, $next, $limits);
    }

    protected function handleRequestUsingNamedLimiter($request, Closure $next, $limiterName, Closure $limiter)
    {
        $request->attributes->set('rl_limiter', $limiterName);

        return parent::handleRequestUsingNamedLimiter($request, $next, $limiterName, $limiter);
    }

    /** Onthoudt per limiet het venster, zodat de reden "N per minuut" kan noemen. */
    protected function handleRequest($request, Closure $next, array $limits)
    {
        $windows = $request->attributes->get('rl_windows', []);
        foreach ($limits as $limit) {
            $windows[$limit->key] = (int) $limit->decaySeconds;
        }
        $request->attributes->set('rl_windows', $windows);

        return parent::handleRequest($request, $next, $limits);
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
     * admin → Te veel verzoeken (wie, vanaf welk IP, welke route, welke limiet).
     */
    protected function buildException($request, $key, $maxAttempts, $responseCallback = null)
    {
        $exception = parent::buildException($request, $key, $maxAttempts, $responseCallback);
        if ($exception instanceof HttpResponseException) {
            return $exception;
        }

        $retryAfter = (int) $this->getTimeUntilNextRetry($key);
        $named = $request->attributes->get('rl_limiter');
        RateLimitLog::record('internal', $request, [
            'max_attempts' => (int) $maxAttempts,
            'retry_after'  => $retryAfter,
            'limiter'      => $named ?: 'throttle',
            'reason'       => $this->reason($request, $key, (int) $maxAttempts, $named),
            'platform'     => $request->header('X-Milmap-Platform'),
        ]);

        return new HttpResponseException(response()->json([
            'message'     => 'Too Many Attempts.',
            'code'        => 'rate_limited',
            'reason'      => 'Te veel verzoeken in korte tijd.',
            'retry_after' => $retryAfter,
        ], 429, $exception->getHeaders()));
    }

    /** "Algemene API-limiet: max 600 per minuut (per gebruiker)". */
    private function reason($request, string $key, int $max, ?string $named): string
    {
        $window = (int) ($request->attributes->get('rl_windows', [])[$key] ?? 60);
        $scope = $request->attributes->get('rl_scopes', [])[$key] ?? null;
        $label = $named ? (self::LIMITER_LABELS[$named] ?? 'Limiet '.$named) : 'Routelimiet';

        return $label.': max '.$max.' per '.self::windowLabel($window).($scope ? ' ('.$scope.')' : '');
    }

    private static function windowLabel(int $seconds): string
    {
        return match (true) {
            $seconds === 60 => 'minuut',
            $seconds === 3600 => 'uur',
            $seconds % 3600 === 0 => ($seconds / 3600).' uur',
            $seconds % 60 === 0 => ($seconds / 60).' minuten',
            default => $seconds.' sec',
        };
    }
}
