<?php
namespace Tests\Unit;
use App\Models\RouteMap;
use App\Support\{RouteDiscoveryFilters, RouteGeometry};
use PHPUnit\Framework\TestCase;

class RouteDiscoveryFiltersTest extends TestCase
{
    private function route(): RouteMap
    {
        return new RouteMap(['sport'=>'hike','total_distance'=>10000,'ascent_m'=>300,'path_polyline'=>RouteGeometry::encode([[52,5],[52.01,5.01],[52,5]])]);
    }
    public function test_distance_and_duration_are_combined(): void
    {
        self::assertTrue(RouteDiscoveryFilters::matches($this->route(), ['min_km'=>9,'max_km'=>11,'min_minutes'=>149,'max_minutes'=>151]));
        self::assertFalse(RouteDiscoveryFilters::matches($this->route(), ['max_km'=>8]));
        self::assertFalse(RouteDiscoveryFilters::matches($this->route(), ['max_minutes'=>140]));
    }
    public function test_unknown_difficulty_and_ascent_are_not_invented(): void
    {
        $route=$this->route(); $route->ascent_m=null;
        self::assertFalse(RouteDiscoveryFilters::matches($route,['min_ascent'=>0]));
        self::assertFalse(RouteDiscoveryFilters::matches($route,['difficulty'=>'easy']));
        self::assertTrue(RouteDiscoveryFilters::matches($route,[]));
    }
    public function test_round_trips_and_radius(): void
    {
        $route=$this->route(); $route->setAttribute('_distance_km',31);
        self::assertTrue(RouteDiscoveryFilters::matches($route,['route_type'=>'loop']));
        self::assertFalse(RouteDiscoveryFilters::matches($route,['route_type'=>'point_to_point']));
        self::assertFalse(RouteDiscoveryFilters::matches($route,['radius_km'=>30]));
    }
}
