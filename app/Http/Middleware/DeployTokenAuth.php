<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Beschermt de /deploy/* endpoints met een gedeeld geheim. De deploy-app
 * draait headless (geen ingelogde gebruiker), dus i.p.v. Sanctum gebruikt hij
 * één statische token in de header `X-Deploy-Token`. De waarde komt uit
 * config('services.deploy.todo_token') ← env('DEPLOY_TODO_TOKEN').
 *
 * Faalt veilig dicht: zolang er geen token is geconfigureerd, weigert elk
 * verzoek (anders zou een leeg geheim de endpoints open zetten).
 */
class DeployTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.deploy.todo_token', '');

        if ($expected === '') {
            return response()->json([
                'message' => 'Deploy token not configured',
                'error'   => 'deploy_token_unconfigured',
            ], 503);
        }

        $provided = (string) (
            $request->header('X-Deploy-Token')
            ?? $request->bearerToken()
            ?? ''
        );

        // Constant-time vergelijking tegen timing-aanvallen.
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Invalid deploy token',
                'error'   => 'invalid_deploy_token',
            ], 401);
        }

        return $next($request);
    }
}
