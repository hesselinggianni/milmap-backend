<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Explicitly public tiles/catalog endpoints may still be cached when
        // anonymous. Authenticated responses must never be shared by HTTP caches.
        if ($request->bearerToken() || $request->user() || !$response->headers->hasCacheControlDirective('public')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
