<?php


use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->group('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'admin.auth'   => \App\Http\Middleware\AdminAuth::class,
            'deploy.token' => \App\Http\Middleware\DeployTokenAuth::class,
            'partner'      => \App\Http\Middleware\EnsureUserIsPartner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API-only app: een niet-geauthenticeerd request mag nooit naar de
        // (niet-bestaande) named route 'login' redirecten. Forceer altijd een
        // 401 JSON-respons i.p.v. de framework-default `route('login')`, die
        // anders "Route [login] not defined." gooit.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Unauthenticated.',
            ], 401);
        });
    })
    ->create();

    