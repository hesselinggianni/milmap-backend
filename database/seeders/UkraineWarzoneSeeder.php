<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Map;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;

/*
 * Lokale stress-/showcasekaart: fictief "oorlogsveld" langs een frontlinie in
 * Oost-Oekraïne, met 1000+ waypoints en 300+ gemarkeerde gebieden (drone-
 * alerts, vijandelijke posten, mijnenvelden, artillerie, routes, ...).
 * Alles gegenereerd/illustratief. Idempotent. Runnen:
 *   php artisan db:seed --class=UkraineWarzoneSeeder
 */
class UkraineWarzoneSeeder extends Seeder
{
    private const TITLE = 'Oekraïne — Frontlinie (stress-test)';

    // [lat, lon] schematische frontlinie noord → zuid
    private const FRONT = [
        [50.40, 36.80], [50.29, 36.94], [49.95, 37.45], [49.71, 37.62], [49.30, 37.70],
        [49.00, 37.80], [48.75, 37.95], [48.59, 38.00], [48.40, 37.85], [48.14, 37.75],
        [47.95, 37.45], [47.78, 37.25], [47.60, 36.60], [47.57, 35.78], [47.45, 35.00],
        [47.20, 34.20], [46.90, 33.50], [46.63, 32.60],
    ];

    private array $placeNames = ['Kupiansk','Lyman','Bakhmut','Avdiivka','Vuhledar','Orikhiv','Vovchansk','Kreminna','Siversk','Toretsk','Pokrovsk','Kurakhove','Huliaipole','Velyka Novosilka','Robotyne','Kherson','Kamianka','Chasiv Yar','Zaporizhzhia','Marinka'];

    public function run(): void
    {
        mt_srand(2022);
        $user = User::where('email', 'perftest@milmap.test')->first() ?? User::first();

        foreach (Map::withTrashed()->where('owner_id', $user->id)->where('title', self::TITLE)->get() as $m) {
            Location::where('map_id', $m->id)->delete();
            Report::where('map_id', $m->id)->delete();
            $m->forceDelete();
        }

        $map = Map::create([
            'title' => self::TITLE, 'owner_id' => $user->id, 'status' => 'active',
            'settings' => ['locationformat' => 'mgrs', 'mgrs_precision' => 5, 'language' => 'nl', 'selectedTheme' => 'outdoors-v12'],
        ]);

        $now = now();
        $loc = [];
        $wp = function (string $prefix, int $n, float $min, float $max, string $color, string $icon, array $names) use (&$loc, $map, $user, $now) {
            for ($i = 1; $i <= $n; $i++) {
                [$lat, $lon] = $this->near(mt_rand((int)($min * 1000), (int)($max * 1000)) / 1000, 0.0);
                $loc[] = [
                    'map_id' => $map->id, 'user_id' => $user->id,
                    'name' => $prefix . ' ' . $names[array_rand($names)] . '-' . sprintf('%03d', $i),
                    'description' => null, 'latitude' => round($lat, 6), 'longitude' => round($lon, 6),
                    'color' => $color, 'icon' => $icon, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        };

        // offset: negatief = eigen zijde (west), positief = vijand (oost), in graden lon
        $wp('OP Vijand', 160, 0.05, 0.9, '#ef4444', 'triangle', $this->placeNames);
        $wp('Vijand CP', 70, 0.3, 1.4, '#dc2626', 'square', $this->placeNames);
        $wp('Vijand arty', 90, 0.4, 1.8, '#b91c1c', 'star', $this->placeNames);
        $wp('Drone-lanceerpunt', 80, 0.1, 1.0, '#f97316', 'dot', $this->placeNames);
        $wp('Eigen post', 160, -0.9, -0.03, '#3b82f6', 'flag', $this->placeNames);
        $wp('Eigen CP', 60, -1.4, -0.3, '#2563eb', 'square', $this->placeNames);
        $wp('Eigen arty', 80, -1.8, -0.4, '#1d4ed8', 'star', $this->placeNames);
        $wp('Veldhospitaal', 40, -2.2, -0.6, '#22c55e', 'dot', $this->placeNames);
        $wp('Munitiedepot', 50, -2.5, -0.5, '#eab308', 'square', $this->placeNames);
        $wp('Luchtafweer', 60, -1.8, -0.2, '#06b6d4', 'triangle', $this->placeNames);
        $wp('Radar/EW', 40, -1.5, 1.5, '#a855f7', 'star', $this->placeNames);
        $wp('Mijn gemeld', 90, -0.6, 0.6, '#f59e0b', 'dot', $this->placeNames);
        $wp('Gevallen drone', 60, -1.0, 1.0, '#14b8a6', 'dot', $this->placeNames);
        $wp('Checkpoint', 50, -2.0, -0.1, '#64748b', 'flag', $this->placeNames);
        $wp('Verzamelpunt', 40, -2.5, -0.8, '#84cc16', 'flag', $this->placeNames);
        foreach (array_chunk($loc, 200) as $c) Location::insert($c);

        // ── Gemarkeerde gebieden ───────────────────────────────────────────
        $rep = [];
        $add = function (string $cat, string $type, ?string $sub, string $label, string $color, string $sym, array $geom, array $extra = []) use (&$rep, $map, $user, $now) {
            $rep[] = array_merge([
                'map_id' => $map->id, 'user_id' => $user->id, 'category' => $cat, 'type' => $type, 'subtype' => $sub,
                'latitude' => $this->center($geom)[0], 'longitude' => $this->center($geom)[1],
                'urgency' => null, 'size' => null, 'risk' => null, 'hazardType' => null, 'description' => null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                'metadata' => json_encode(['reportLabel' => $label, 'reportColor' => $color, 'reportSymbol' => $sym, 'saluteGeometry' => $geom]),
            ], $extra);
        };

        for ($i = 0; $i < 70; $i++) { // drone-alerts (cirkels 3-12 km)
            [$lat, $lon] = $this->near(mt_rand(-150, 150) / 100, 0);
            $add('threat', 'drone', 'enemy', 'Drone alert', '#f97316', 'UAV', $this->circle($lat, $lon, mt_rand(30, 120) / 1000),
                ['urgency' => 'high', 'description' => 'Drone alert — FPV/Shahed-activiteit']);
        }
        for ($i = 0; $i < 60; $i++) { // vijandelijke posten
            [$lat, $lon] = $this->near(mt_rand(5, 120) / 100, 0);
            $add('threat', 'infantry', 'enemy', 'Vijand post', '#ef4444', 'INF', $this->blob($lat, $lon, mt_rand(3, 10) / 1000),
                ['urgency' => 'high', 'size' => '>20']);
        }
        for ($i = 0; $i < 25; $i++) { // pantser
            [$lat, $lon] = $this->near(mt_rand(10, 150) / 100, 0);
            $add('threat', 'tank', 'enemy', 'Pantsergroep', '#b91c1c', 'ARM', $this->blob($lat, $lon, mt_rand(4, 12) / 1000), ['urgency' => 'high']);
        }
        for ($i = 0; $i < 40; $i++) { // artillerie bereik
            [$lat, $lon] = $this->near(mt_rand(30, 180) / 100, 0);
            $add('threat', 'artillery', 'enemy', 'Artillerie-bereik', '#dc2626', 'ART', $this->circle($lat, $lon, mt_rand(60, 200) / 1000), ['urgency' => 'medium']);
        }
        for ($i = 0; $i < 45; $i++) { // mijnenvelden
            [$lat, $lon] = $this->near(mt_rand(-80, 80) / 100, 0);
            $add('hazard', 'mines', null, 'Mijnenveld', '#f59e0b', 'MIN', $this->blob($lat, $lon, mt_rand(4, 14) / 1000),
                ['risk' => 'high', 'hazardType' => 'mines']);
        }
        for ($i = 0; $i < 30; $i++) { // EW-zones
            [$lat, $lon] = $this->near(mt_rand(-100, 150) / 100, 0);
            $add('threat', 'drone', 'enemy', 'EW / jamming-zone', '#a855f7', 'EW', $this->circle($lat, $lon, mt_rand(40, 90) / 1000), ['urgency' => 'medium']);
        }
        for ($i = 0; $i < 40; $i++) { // eigen stellingen
            [$lat, $lon] = $this->near(-mt_rand(5, 100) / 100, 0);
            $add('threat', 'infantry', 'friendly', 'Eigen stelling', '#3b82f6', 'INF', $this->blob($lat, $lon, mt_rand(3, 9) / 1000), ['urgency' => 'low']);
        }
        for ($i = 0; $i < 20; $i++) { // eigen luchtafweer-dekking
            [$lat, $lon] = $this->near(-mt_rand(10, 120) / 100, 0);
            $add('threat', 'drone', 'friendly', 'Luchtafweer-dekking', '#06b6d4', 'AA', $this->circle($lat, $lon, mt_rand(40, 100) / 1000), ['urgency' => 'low']);
        }
        for ($i = 0; $i < 20; $i++) { // aanlooproutes (lijnen, richting oost)
            $s = $this->near(-mt_rand(20, 120) / 100, 0);
            $pts = [$s];
            for ($k = 1; $k <= 4; $k++) $pts[] = [$s[0] + (mt_rand(-60, 60) / 1000) * $k, $s[1] + 0.09 * $k];
            $add('military', 'approach', null, 'Aanlooproute', '#3b82f6', 'RTE', $this->line($pts), ['subtype' => null, 'description' => 'Aanloop eigen eenheden']);
        }
        for ($i = 0; $i < 15; $i++) { // vijandelijke aanvalsassen (richting west)
            $s = $this->near(mt_rand(50, 150) / 100, 0);
            $pts = [$s];
            for ($k = 1; $k <= 4; $k++) $pts[] = [$s[0] + (mt_rand(-60, 60) / 1000) * $k, $s[1] - 0.09 * $k];
            $add('military', 'approach', 'enemy', 'Vijandelijke aanvalsas', '#ef4444', 'AXS', $this->line($pts));
        }
        for ($i = 0; $i < 12; $i++) { // bevoorradingsroutes
            $s = $this->near(-mt_rand(150, 250) / 100, 0);
            $pts = [$s];
            for ($k = 1; $k <= 6; $k++) $pts[] = [$s[0] + (mt_rand(-40, 40) / 1000) * $k, $s[1] + 0.2 * $k];
            $add('military', 'approach', null, 'Bevoorradingsroute', '#eab308', 'MSR', $this->line($pts));
        }
        // de frontlinie zelf, gesplitst in segmenten
        for ($i = 0; $i < count(self::FRONT) - 1; $i++) {
            $add('military', 'approach', null, 'Frontlinie', '#facc15', 'FLOT', $this->line([array_reverse(self::FRONT[$i]) ? self::FRONT[$i] : self::FRONT[$i], self::FRONT[$i + 1]]), ['description' => 'Frontlinie (schematisch)']);
        }
        foreach (array_chunk($rep, 100) as $c) Report::insert($c);

        $this->command?->info("Klaar: kaart {$map->id} — " . count($loc) . ' waypoints, ' . count($rep) . ' gebieden (user ' . $user->email . ')');
    }

    /** Punt langs de frontlijn met zijwaartse lon-offset (graden) + jitter. */
    private function near(float $off, float $unused): array
    {
        $f = self::FRONT; $t = mt_rand(0, 10000) / 10000 * (count($f) - 1);
        $i = min((int)$t, count($f) - 2); $r = $t - $i;
        $lat = $f[$i][0] + ($f[$i + 1][0] - $f[$i][0]) * $r + (mt_rand(-300, 300) / 1000) * abs($off) * 0.4;
        $lon = $f[$i][1] + ($f[$i + 1][1] - $f[$i][1]) * $r + $off + (mt_rand(-100, 100) / 1000);
        return [$lat, $lon];
    }

    private function circle(float $lat, float $lon, float $rDeg): array
    {
        $ring = [];
        for ($a = 0; $a <= 24; $a++) {
            $th = ($a % 24) / 24 * 2 * M_PI;
            $ring[] = [round($lon + $rDeg * 1.5 * cos($th), 6), round($lat + $rDeg * sin($th), 6)];
        }
        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    private function blob(float $lat, float $lon, float $rDeg): array
    {
        $ring = []; $n = mt_rand(5, 8);
        for ($a = 0; $a < $n; $a++) {
            $th = $a / $n * 2 * M_PI; $r = $rDeg * (0.6 + mt_rand(0, 80) / 100);
            $ring[] = [round($lon + $r * 1.5 * cos($th), 6), round($lat + $r * sin($th), 6)];
        }
        $ring[] = $ring[0];
        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    private function line(array $latlon): array
    {
        return ['type' => 'LineString', 'coordinates' => array_map(fn ($p) => [round($p[1], 6), round($p[0], 6)], $latlon)];
    }

    private function center(array $g): array
    {
        $pts = $g['type'] === 'Polygon' ? $g['coordinates'][0] : $g['coordinates'];
        $lon = array_sum(array_column($pts, 0)) / count($pts);
        $lat = array_sum(array_column($pts, 1)) / count($pts);
        return [round($lat, 6), round($lon, 6)];
    }
}
