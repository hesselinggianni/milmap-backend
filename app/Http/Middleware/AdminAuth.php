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

        // Require an explicitly scoped, short-lived personal token. Wildcards
        // and SPA transient tokens must not grant administration privileges.
        $token = Auth::user()->currentAccessToken();
        if (!$token instanceof \Laravel\Sanctum\PersonalAccessToken ||
            !in_array('admin', $token->abilities ?? [], true) ||
            !$token->expires_at || $token->expires_at->isPast() ||
            !$token->created_at || $token->created_at->lte(now()->subHours(8))) {
            return response()->json([
                'message' => 'Forbidden - Admin token required',
                'error' => 'admin_token_required'
            ], 403);
        }

        return $next($request);
    }
}
