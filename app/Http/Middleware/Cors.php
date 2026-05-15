<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Update this to your frontend URL in production
        $allowOrigins = ['*','http://localhost:8080','localhost:8080','https://localhost:8081','localhost:8081',  ]; // Change this to your frontend URL

        $origin = $request->headers->get('Origin');
        if (in_array($origin, $allowOrigins)) {
            return $next($request)
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, X-CSRF-TOKEN, Authorization')
                ->header('Access-Control-Expose-Headers', 'X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true');
        }

        return $next($request);
    }
}
