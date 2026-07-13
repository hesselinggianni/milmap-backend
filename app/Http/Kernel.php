<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{

    /**
     * The application's middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'api' => [
            \App\Http\Middleware\Cors::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

    ];

    /**
     * The application's route middleware.
     *
     * ⚠️ LET OP: dit bestand is LEGACY en wordt door Laravel 11+ NIET meer
     * geladen — de echte registratie staat in bootstrap/app.php
     * (->withMiddleware). Een alias alléén hier toevoegen geeft op runtime
     * "Target class [...] does not exist". Houd beide in sync of ruim dit
     * bestand op.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'admin.auth'   => \App\Http\Middleware\AdminAuth::class,
        'deploy.token' => \App\Http\Middleware\DeployTokenAuth::class,
        'partner'      => \App\Http\Middleware\EnsureUserIsPartner::class,
    ];

}
