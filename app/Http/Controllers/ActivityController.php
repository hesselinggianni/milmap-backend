<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD voor opgenomen GPS-activiteiten (hardlopen/fietsen/wandelen) van de
 * ingelogde gebruiker. Stijl gespiegeld aan MissionTrackController: inline
 * validatie, Auth::id()-scoping, raw JSON-responses.
 */
class ActivityController extends Controller
{
    /**
     * Lijst van eigen activiteiten (nieuwste eerst), zonder het zware
     * `points`-track — die haal je per activiteit op via show().
     * GET /api/v1/activities
     */
    public function index(Request $request)
    {
        $activities = Activity::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->makeHidden('points');

        return response()->json(['activities' => $activities]);
    }

    /**
     * Eén activiteit incl. volledig punten-track.
     * GET /api/v1/activities/{id}
     */
    public function show($id)
    {
        $activity = Activity::where('user_id', Auth::id())->findOrFail($id);

        return response()->json(['activity' => $activity]);
    }

    /**
     * Sla een afgeronde opname op.
     * POST /api/v1/activities
     */
    public function store(Request $request)
    {
        // Laravel logt een 422 niet: de app kreeg dus wél een foutmelding
        // ("opslaan mislukt") terwijl er nergens iets terug te vinden was
        // waaróm. Hier vangen we de validatiefout af, loggen we precies welk
        // veld werd geweigerd (plus de vorm van de payload — niet de punten
        // zelf, dat zijn er duizenden) en gooien we 'm daarna gewoon door.
        try {
            $data = $this->validateActivity($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Illuminate\Support\Facades\Log::warning('[activity] opslaan geweigerd', [
                'user_id' => Auth::id(),
                'errors'  => $e->errors(),
                'type'    => $request->input('type'),
                'points'  => is_array($request->input('points')) ? count($request->input('points')) : null,
                'keys'    => array_keys($request->all()),
            ]);
            throw $e;
        }

        return $this->persistActivity($request, $data);
    }

    private function validateActivity(Request $request): array
    {
        return $request->validate([
            // 'drive' hoort er ook bij: de app biedt vier verplaatsingswijzen
            // aan (zie NavigationView.vue's travelModes), maar deze regel
            // kende er maar drie — een navigatie in de auto liep daardoor
            // altijd op een 422 en werd nooit opgeslagen.
            'type' => ['required', 'string', 'in:run,ride,walk,drive'],
            'title' => ['nullable', 'string', 'max:255'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date'],
            'distance_m' => ['required', 'numeric', 'min:0'],
            'moving_time_s' => ['required', 'numeric', 'min:0'],
            'elapsed_time_s' => ['required', 'numeric', 'min:0'],
            'elevation_gain_m' => ['nullable', 'numeric', 'min:0'],
            'avg_pace_s_per_km' => ['nullable', 'numeric', 'min:0'],
            'avg_speed_kmh' => ['nullable', 'numeric', 'min:0'],
            'avg_power_w' => ['nullable', 'numeric', 'min:0'],
            'calories' => ['nullable', 'numeric', 'min:0'],
            'points' => ['required', 'array', 'min:2', 'max:20000'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lon' => ['required', 'numeric', 'between:-180,180'],
            'points.*.t' => ['nullable', 'numeric'],
            'points.*.ele' => ['nullable', 'numeric'],
            'points.*.speed' => ['nullable', 'numeric', 'min:0'],
        ], [
            // Zonder eigen teksten stuurde Laravel de rauwe sleutel terug
            // ("validation.min.array") en toonde de app die letterlijk. Dit is
            // veruit de meest voorkomende weigering: een opname zonder
            // GPS-ontvangst levert 0 of 1 punt op.
            'points.required' => 'Deze opname bevat geen GPS-punten. Zet locatietoegang aan en zorg voor ontvangst voordat je start.',
            'points.min' => 'Deze opname bevat te weinig GPS-punten om op te slaan (minimaal 2 nodig).',
            'points.max' => 'Deze opname bevat te veel punten om in één keer op te slaan.',
            'type.in' => 'Onbekende verplaatsingswijze.',
        ]);
    }

    private function persistActivity(Request $request, array $data)
    {
        $activity = Activity::create([
            ...$data,
            'user_id' => Auth::id(),
        ]);

        return response()->json(['activity' => $activity->makeHidden('points')], 201);
    }

    /**
     * Verwijder een eigen activiteit.
     * DELETE /api/v1/activities/{id}
     */
    public function destroy($id)
    {
        $activity = Activity::where('user_id', Auth::id())->findOrFail($id);
        $activity->delete();

        return response()->json(['deleted' => true]);
    }
}
