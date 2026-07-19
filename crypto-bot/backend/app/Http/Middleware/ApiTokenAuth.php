<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simple static-bearer-token gate for the dashboard API. Set BOT_API_TOKEN in
 * the environment; if it is empty (local dev) the gate is open. This is a
 * single-user dashboard, so a shared token is sufficient — swap for Sanctum if
 * you later add multiple users.
 */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('bot.api_token');

        if (! empty($expected) && ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
