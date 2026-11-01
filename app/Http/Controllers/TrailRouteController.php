<?php

namespace App\Http\Controllers;

use App\Models\TrailRoute;
use App\Services\TrailRouteExtrasService;
use App\Support\TrailRoutePresenter;
use Illuminate\Http\JsonResponse;

/**
 * Eén geïmporteerde OpenStreetMap-route bekijken (het detail onder "Routes
 * ontdekken"). Opslaan als eigen route staat in PublicRouteController::cloneTrailRoute.
 */
class TrailRouteController extends Controller
{
    /** GET /api/v1/trail-routes/{slug} */
    public function show(string $slug): JsonResponse
    {
        $route = TrailRoute::published()->where('slug', $slug)->firstOrFail();

        return response()->json(['route' => TrailRoutePresenter::detail($route)]);
    }

    /** GET /api/v1/trail-routes/{slug}/extras — wegtypen, ondergrond en hoogtepunten (Overpass, gecachet). */
    public function extras(string $slug): JsonResponse
    {
        $route = TrailRoute::published()->where('slug', $slug)->firstOrFail();

        return response()->json(TrailRouteExtrasService::extras($route));
    }
}
