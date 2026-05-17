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

        return $next($request);
    }
}
