<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\GarminAccount;
use App\Services\GarminService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Haalt de autoritatieve activiteitdata op bij Garmin (nooit de ping-payload
 * zelf vertrouwen — zie GarminWebhookController) en zet 'm om naar een
 * Activity-rij met source='garmin'. Idempotent via updateOrCreate op
 * garmin_activity_id, want Garmin kan voor dezelfde activiteit meermaals
 * pingen (bewerkingen, retries).
 */
class ImportGarminActivity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    /** @param array<string,mixed> $pingEntry */
    public function __construct(private int $garminAccountId, private array $pingEntry) {}

    public function handle(GarminService $garmin): void
    {
        $account = GarminAccount::find($this->garminAccountId);
        if (! $account) {
            return;
        }

        // VERIFY: exacte veldnaam voor de ophaal-URL in de ping-payload.
        $fetchUrl = $this->pingEntry['callbackURL'] ?? $this->pingEntry['activityDetailsUrl'] ?? null;
        if (! $fetchUrl) {
            Log::warning('Garmin-ping zonder ophaal-URL, overgeslagen.', ['entry' => $this->pingEntry]);
            return;
        }

        $data = $garmin->fetchActivityData($account, $fetchUrl);

        // VERIFY: exacte response-shape (met name het track-punten-formaat).
        $garminActivityId = (string) ($data['activityId'] ?? $data['summaryId'] ?? '');
        if ($garminActivityId === '') {
            Log::warning('Garmin-activiteitdata zonder activityId, overgeslagen.', ['entry' => $this->pingEntry]);
            return;
        }

        $points = collect($data['samples'] ?? $data['points'] ?? [])
            ->map(fn ($s) => [
                'lat' => (float) ($s['latitudeInDegree'] ?? $s['lat'] ?? 0),
                'lon' => (float) ($s['longitudeInDegree'] ?? $s['lon'] ?? 0),
                't' => $s['startTimeInSeconds'] ?? $s['t'] ?? null,
                'ele' => $s['elevationInMeters'] ?? $s['ele'] ?? null,
                'speed' => $s['speedMetersPerSecond'] ?? $s['speed'] ?? null,
            ])
            ->values()
            ->all();

        $type = $this->mapActivityType($data['activityType'] ?? null);
        $deviceName = $this->deviceNameFor($account, $data['deviceName'] ?? $data['deviceId'] ?? null);

        Activity::updateOrCreate(
            ['garmin_activity_id' => $garminActivityId, 'user_id' => $account->user_id],
            [
                'source' => 'garmin',
                'garmin_device_name' => $deviceName,
                'type' => $type,
                'title' => $data['activityName'] ?? null,
                'started_at' => isset($data['startTimeInSeconds']) ? now()->setTimestamp($data['startTimeInSeconds']) : now(),
                'ended_at' => isset($data['startTimeInSeconds'], $data['durationInSeconds'])
                    ? now()->setTimestamp($data['startTimeInSeconds'] + $data['durationInSeconds'])
                    : null,
                'distance_m' => (int) round($data['distanceInMeters'] ?? 0),
                'moving_time_s' => (int) ($data['durationInSeconds'] ?? 0),
                'elapsed_time_s' => (int) ($data['durationInSeconds'] ?? 0),
                'elevation_gain_m' => (int) round($data['totalElevationGainInMeters'] ?? 0),
                'calories' => isset($data['activeKilocalories']) ? (int) $data['activeKilocalories'] : null,
                'points' => $points,
            ]
        );

        $account->update(['last_synced_at' => now()]);
    }

    /** VERIFY: Garmin's exacte activiteit-type-vocabulaire. */
    private function mapActivityType(?string $garminType): string
    {
        $t = strtolower((string) $garminType);
        return match (true) {
            str_contains($t, 'run') => 'run',
            str_contains($t, 'cycl'), str_contains($t, 'bik') => 'ride',
            str_contains($t, 'walk'), str_contains($t, 'hik') => 'walk',
            str_contains($t, 'drive'), str_contains($t, 'motor') => 'drive',
            default => 'run',
        };
    }

    private function deviceNameFor(GarminAccount $account, mixed $deviceIdOrName): ?string
    {
        if (! $deviceIdOrName) {
            return null;
        }
        $match = collect($account->devices ?? [])
            ->first(fn ($d) => ($d['device_id'] ?? null) === (string) $deviceIdOrName);

        return $match['model_name'] ?? (is_string($deviceIdOrName) ? $deviceIdOrName : 'Garmin');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Garmin-activiteit-import mislukt', [
            'garmin_account_id' => $this->garminAccountId,
            'error' => $exception->getMessage(),
        ]);
    }
}
