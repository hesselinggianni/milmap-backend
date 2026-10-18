<?php

namespace App\Http\Middleware;

use App\Services\RateLimitLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vangnet voor admin → Te veel verzoeken: elke 429 die niet door de
 * throttle-middleware kwam (controllers met een eigen RateLimiter::tooManyAttempts
 * of een eigen tijdslimiet) wordt hier alsnog met een reden vastgelegd.
 * Zo hoeven die controllers zelf niets te loggen.
 */
class LogRateLimitResponses
{
    /**
     * Reden per route voor de handmatige limieten. Cijfers komen uit de
     * controllers zelf; pas ze hier aan als je daar een limiet wijzigt.
     */
    private const REASONS = [
        'api/v1/login/code/request'                  => 'Inlogcode aanvragen: max 5 per e-mailadres of 30 per IP per uur',
        'api/v1/admin/request-code'                  => 'Admin-inlogcode aanvragen: max 5 per e-mailadres per uur',
        'api/v1/admin/verify-code'                   => 'Admin-inlogcode controleren: max 5 pogingen per 15 minuten',
        'api/v1/auth/handoff/exchange'               => 'Handoff-login: max 20 pogingen per 5 minuten per IP',
        'api/v1/api-usage'                           => 'Verbruiksmeldingen: te veel batches per gebruiker of netwerk',
        'api/v1/maps/{mapId}/user-locations/update'  => 'Live locatie: meer dan 1 update per seconde per gebruiker',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 429 && ! $request->attributes->get('rate_limit_logged')) {
            $this->log($request, $response);
        }

        return $response;
    }

    private function log(Request $request, Response $response): void
    {
        try {
            $uri = $request->route()?->uri() ?? $request->path();
            $body = json_decode((string) $response->getContent(), true);
            $fallback = is_array($body) ? ($body['message'] ?? $body['error'] ?? null) : null;

            RateLimitLog::record('internal', $request, [
                'limiter'     => 'controller',
                'reason'      => self::REASONS[$uri] ?? ($fallback ? 'Eigen limiet: '.$fallback : 'Te veel verzoeken (429)'),
                'retry_after' => is_numeric($response->headers->get('Retry-After')) ? (int) $response->headers->get('Retry-After') : null,
                'user_id'     => auth('sanctum')->id(),
                'platform'    => $request->header('X-Milmap-Platform'),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
