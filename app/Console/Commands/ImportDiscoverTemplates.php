<?php
namespace App\Console\Commands;

use App\Models\{Map, PublicProfile, RouteMap, User};
use App\Support\{PublicRoutePresenter, RouteGeometry};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;

class ImportDiscoverTemplates extends Command
{
    protected $signature = 'routes:import-templates {--dry-run}';
    protected $description = 'Import attributed OpenStreetMap templates into Discover';

    public function handle(): int
    {
        $items = [];
        foreach (glob(database_path('data/discover/*.json')) as $file) {
            try {
                $item = self::parse(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR), basename($file, '.json'));
                $items[] = $item;
                $this->info('OK: '.$item['title']);
            } catch (\Throwable $e) { $this->warn(basename($file).': '.$e->getMessage()); }
        }
        if (!$items) return self::FAILURE;
        if ($this->option('dry-run')) return self::SUCCESS;
        DB::transaction(function () use ($items) {
            $user = User::firstOrCreate(['email' => 'discover-catalog@milmap.invalid'], [
                'first_name' => 'MilMap', 'last_name' => 'Routecatalogus',
                'password' => Hash::make(Str::random(80)),
            ]);
            $profile = PublicProfile::firstOrCreate(['user_id' => $user->id], [
                'handle' => 'milmap-routes', 'display_name' => 'MilMap · OpenStreetMap',
                'bio' => 'Route templates · © OpenStreetMap-bijdragers · ODbL 1.0 · https://www.openstreetmap.org/copyright',
                'enabled' => true,
            ]);
            if (!$profile->enabled) throw new \RuntimeException('Catalogusprofiel is uitgeschakeld.');
            $map = Map::firstOrCreate(['owner_id' => $user->id, 'title' => 'Discover · OpenStreetMap templates'], ['status' => 'active', 'settings' => ['language' => 'nl']]);
            foreach ($items as $item) {
                $locations = [];
                foreach (RouteGeometry::sample($item['line'], 30) as $i => $p) $locations[] = ['id' => $i+1, 'no' => $i+1, 'lat' => $p[0], 'lon' => $p[1], 'title' => ''];
                $distance = 0;
                for ($i=1; $i<count($item['line']); $i++) $distance += PublicRoutePresenter::haversine(...[...$item['line'][$i-1], ...$item['line'][$i]]);
                RouteMap::updateOrCreate(['owner_id' => $user->id, 'source' => 'osm', 'source_ref' => $item['ref']], [
                    'map_id' => $map->id, 'title' => $item['title'], 'sport' => 'hike', 'speed' => 5,
                    'locations' => $locations, 'path_polyline' => RouteGeometry::encode(RouteGeometry::simplify($item['line'])),
                    'total_distance' => round($distance), 'total_time' => round($distance/1000/5*3600),
                    'color' => '#24845b', 'meta' => ['kind' => 'route', 'template' => true],
                    'is_public' => true, 'published_at' => now(),
                    'attribution' => '© OpenStreetMap-bijdragers · ODbL 1.0 · https://www.openstreetmap.org/copyright · https://www.openstreetmap.org/relation/'.$item['ref'],
                ]);
            }
        });
        $this->info(count($items).' templates beschikbaar in Discover.');
        return self::SUCCESS;
    }

    /** Only join shared node IDs: disconnected/branched routes need review. */
    public static function parse(array $data, string $id): array
    {
        $nodes=[]; $ways=[]; $relation=null;
        foreach ($data['elements'] ?? [] as $e) {
            if ($e['type']==='node') $nodes[$e['id']]=[(float)$e['lat'],(float)$e['lon']];
            if ($e['type']==='way') $ways[$e['id']]=$e['nodes'];
            if ($e['type']==='relation' && (string)$e['id']===$id) $relation=$e;
        }
        if (!$relation || empty($relation['tags']['name']) || !in_array($relation['tags']['route'] ?? '', ['foot','hiking'], true)) throw new \RuntimeException('Named walking route missing');
        $parts=[];
        foreach ($relation['members'] as $m) {
            if ($m['type']==='relation') throw new \RuntimeException('Nested route requires review');
            if ($m['type']!=='way') continue;
            if (!in_array($m['role'], ['', 'forward', 'backward'], true)) throw new \RuntimeException('Branch requires review');
            $part=$ways[$m['ref']] ?? [];
            if (count($part)<2) throw new \RuntimeException('Missing way');
            $parts[]=$part;
        }
        $chain=array_shift($parts) ?? [];
        while ($parts) {
            $joined=false;
            foreach ($parts as $k=>$p) {
                if (end($chain)===$p[0]) $chain=array_merge($chain,array_slice($p,1));
                elseif (end($chain)===end($p)) $chain=array_merge($chain,array_slice(array_reverse($p),1));
                elseif ($chain[0]===end($p)) $chain=array_merge(array_slice($p,0,-1),$chain);
                elseif ($chain[0]===$p[0]) $chain=array_merge(array_slice(array_reverse($p),0,-1),$chain);
                else continue;
                unset($parts[$k]); $joined=true; break;
            }
            if (!$joined) throw new \RuntimeException('Disconnected route requires review');
        }
        if (count($chain)<2) throw new \RuntimeException('Empty route');
        return ['ref'=>$id, 'title'=>$relation['tags']['name'], 'line'=>array_map(fn($n)=>$nodes[$n] ?? throw new \RuntimeException('Missing node'),$chain)];
    }
}
