<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return response()->json([
                'message' => 'Unauthorized',
                'error' => 'unauthenticated'
            ], 401);
        }

        // Check if user is admin
        if (!Auth::user()->is_admin) {
            return response()->json([
                'message' => 'Forbidden - Admin access required',
                'error' => 'not_admin'
            ], 403);
        }

        // Defense-in-depth: the admin area may only be reached with a token that
        // was minted through the admin login flow (scoped 'admin'), not with a
        // regular app session token — so a leaked/misused user token can't touch
        // admin endpoints even if that user happens to be an admin. Legacy
        // unscoped ['*'] tokens and the stateful SPA TransientToken both still
        // satisfy tokenCan(), so this adds no breakage for existing sessions.
        if (!Auth::user()->tokenCan('admin')) {
            return response()->json([
                'message' => 'Forbidden - Admin token required',
                'error' => 'admin_token_required'
            ], 403);
        }

        return $next($request);
    }
}
