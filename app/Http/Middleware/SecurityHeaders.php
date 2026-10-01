<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basis-beveiligingsheaders op elke respons. Bewust zonder CSP: de API levert
 * JSON en de SPA laadt externe bronnen (kaarttiles, Stripe) die eerst
 * geïnventariseerd moeten worden. Headers die al gezet zijn blijven staan.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        ];
        // HSTS alleen over https, anders negeert de browser 'm en breekt
        // lokaal ontwikkelen niet.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
