<?php

namespace App\Jobs;

use App\Models\GarminAccount;
use App\Models\GeneratedRoute;
use App\Models\RouteMap;
use App\Services\GarminService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pusht een routekaart als "course" naar Garmin Connect. Queued omdat de
 * externe API-call de request niet mag blokkeren (zie
 * RouteMapController::pushGarmin).
 */
class PushRouteMapToGarmin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 60;

    public function __construct(private string $routeMapId, private int $userId) {}

    public function handle(GarminService $garmin): void
    {
        $routeMap = RouteMap::find($this->routeMapId);
        if (! $routeMap) {
            return;
        }

        $account = GarminAccount::where('user_id', $this->userId)->first();
        if (! $account) {
            $routeMap->update([
                'garmin_push_status' => 'failed',
                'garmin_push_error' => 'Garmin-account niet (meer) gekoppeld.',
            ]);
            return;
        }

        $points = $this->resolvePoints($routeMap);
        if (empty($points)) {
            $routeMap->update([
                'garmin_push_status' => 'failed',
                'garmin_push_error' => 'Geen routepunten om te pushen.',
            ]);
            return;
        }

        $courseId = $garmin->pushCourse($account, $routeMap->title ?: 'MilMap route', $points);

        $routeMap->update([
            'garmin_course_id' => $courseId,
            'garmin_push_status' => 'pushed',
            'garmin_pushed_at' => now(),
            'garmin_push_error' => null,
        ]);
    }

    /**
     * Voorkeur: de meest recente gegenereerde/toegepaste route (het echte
     * gelopen pad). Geen GeneratedRoute? Val terug op de losse checkpoints
     * uit route_maps.locations.
     *
     * @return array<int, array{lat:float, lon:float}>
     */
    private function resolvePoints(RouteMap $routeMap): array
    {
        $generated = GeneratedRoute::forRouteMap($routeMap->id)
            ->whereIn('status', ['generated', 'applied'])
            ->latest()
            ->first();

        if ($generated) {
            $coords = $generated->getRouteCoordinates();
            if (! empty($coords)) {
                return $coords;
            }
        }

        return collect($routeMap->locations ?? [])
            ->filter(fn ($loc) => isset($loc['lat'], $loc['lon']) && is_numeric($loc['lat']) && is_numeric($loc['lon']))
            ->map(fn ($loc) => [
                'lat' => (float) $loc['lat'],
                'lon' => (float) $loc['lon'],
            ])
            ->values()
            ->all();
    }

    public function failed(\Throwable $exception): void
    {
        RouteMap::where('id', $this->routeMapId)->update([
            'garmin_push_status' => 'failed',
            'garmin_push_error' => $exception->getMessage(),
        ]);

        Log::error("Garmin-push mislukt voor routemap {$this->routeMapId}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
