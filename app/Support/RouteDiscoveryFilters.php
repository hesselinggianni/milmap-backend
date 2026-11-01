<?php
namespace App\Support;

use App\Models\RouteMap;

final class RouteDiscoveryFilters
{
    public static function matches(RouteMap $route, array $filters): bool
    {
        $distance = (float) $route->total_distance / 1000;
        $ascent = $route->ascent_m;
        $speed = ['hike'=>5, 'run'=>9, 'bike'=>17, 'mtb'=>12][$route->sport] ?? 5;
        $climbRate = ['hike'=>600, 'run'=>400, 'bike'=>500, 'mtb'=>400][$route->sport] ?? 600;
        $duration = ($distance / $speed + ($ascent ?? 0) / $climbRate) * 60;
        foreach (['km'=>$distance, 'minutes'=>$duration, 'ascent'=>$ascent] as $unit=>$value) {
            foreach (['min', 'max'] as $bound) {
                $key = $bound.'_'.$unit;
                if (!isset($filters[$key])) continue;
                if ($value === null || ($unit !== 'ascent' && $distance <= 0)) return false;
                if ($bound === 'min' && $value < $filters[$key]) return false;
                if ($bound === 'max' && $value > $filters[$key]) return false;
            }
        }
        if (!empty($filters['difficulty']) && $route->difficulty !== $filters['difficulty']) return false;
        if (!empty($filters['route_type'])) {
            $line = filled($route->path_polyline) ? RouteGeometry::decode($route->path_polyline) : PublicRoutePresenter::routeLine(PublicRoutePresenter::cleanLocations($route->locations ?? []));
            if (count($line) < 2) return false;
            $loop = PublicRoutePresenter::haversine(...[...$line[0], ...$line[count($line)-1]]) <= 100;
            if (($filters['route_type'] === 'loop') !== $loop) return false;
        }
        if (isset($filters['radius_km']) && $route->getAttribute('_distance_km') > $filters['radius_km']) return false;
        return true;
    }
}
