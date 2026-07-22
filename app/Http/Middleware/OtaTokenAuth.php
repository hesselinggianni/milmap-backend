<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Beschermt het publiceren van OTA-bundels (POST /ota/publish) met een gedeeld
 * geheim, net als DeployTokenAuth. Header: X-Ota-Token. Waarde komt uit
 * config('services.ota.publish_token') ← env('OTA_PUBLISH_TOKEN').
 *
 * Faalt veilig dicht: zonder geconfigureerd geheim weigert elk verzoek.
 * Het check-endpoint (waar de app zelf op update-checkt) staat hier los van
 * en is publiek — vergelijkbaar met /share/{token}.
 */
class OtaTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.ota.publish_token', '');

        if ($expected === '') {
            return response()->json([
                'message' => 'OTA publish token not configured',
                'error'   => 'ota_token_unconfigured',
            ], 503);
        }

        $provided = (string) (
            $request->header('X-Ota-Token')
            ?? $request->bearerToken()
            ?? ''
        );

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Invalid OTA publish token',
                'error'   => 'invalid_ota_token',
            ], 401);
        }

        return $next($request);
    }
}
