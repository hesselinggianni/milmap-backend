<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Alles wat de kaart bij het openen nodig heeft, in één verzoek.
 * GET /api/v1/maps/{id}/bootstrap
 *
 * Voorheen vuurde de app hiervoor vier losse calls af (kaart, routekaarten,
 * meldingen, waypoints) naast nog een tiental andere. Met een beperkt aantal
 * PHP-workers stonden die in de rij en kwam de laatste pas na ~1,5–2 s binnen.
 *
 * Bewust hergebruik van de bestaande controllers: elk onderdeel doet z'n eigen
 * autorisatie en geeft precies hetzelfde antwoord als z'n losse endpoint. Een
 * onderdeel dat faalt (bv. 403 op waypoints) laat de rest niet mislukken; de
 * client krijgt per onderdeel { status, body } en valt zo nodig terug.
 */
class MapBootstrapController extends Controller
{
    public function show(Request $request, string $id)
    {
        $routeMapsRequest = Request::create('/api/v1/routemaps', 'GET', ['map_id' => $id]);
        $routeMapsRequest->setUserResolver($request->getUserResolver());

        return response()->json([
            'map'       => $this->part(fn () => app(MapController::class)->show($id)),
            'routemaps' => $this->part(fn () => app(RouteMapController::class)->index($routeMapsRequest)),
            'reports'   => $this->part(fn () => app(ReportController::class)->getByMap($request, $id)),
            'waypoints' => $this->part(fn () => app(MapWaypointController::class)->index($id)),
        ]);
    }

    /** Voer één onderdeel uit en vang z'n fout af als status-code. */
    private function part(callable $call): array
    {
        try {
            $response = $call();
            if ($response instanceof JsonResponse) {
                return ['status' => $response->getStatusCode(), 'body' => $response->getData(true)];
            }
            return ['status' => 200, 'body' => $response];
        } catch (HttpExceptionInterface $e) {
            return ['status' => $e->getStatusCode(), 'body' => ['message' => $e->getMessage()]];
        } catch (ModelNotFoundException $e) {
            return ['status' => 404, 'body' => ['message' => 'Niet gevonden.']];
        } catch (AuthorizationException $e) {
            return ['status' => 403, 'body' => ['message' => 'Geen toegang.']];
        } catch (ValidationException $e) {
            return ['status' => 422, 'body' => ['message' => $e->getMessage(), 'errors' => $e->errors()]];
        } catch (\Throwable $e) {
            report($e);
            return ['status' => 500, 'body' => ['message' => 'Serverfout.']];
        }
    }
}
