<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Existing routes may have been created by an external collaborator.
        // Their parent map, including a soft-deleted map, owns the route data.
        DB::table('route_maps')->select(['id', 'map_id', 'connect_organization_id'])->orderBy('id')->chunkById(200, function ($routes) {
            $parents = DB::table('maps')->whereIn('id', $routes->pluck('map_id'))->pluck('connect_organization_id', 'id');
            foreach ($routes as $route) {
                if ($parents->has($route->map_id) && (int) $route->connect_organization_id !== (int) $parents[$route->map_id]) {
                    DB::table('route_maps')->where('id', $route->id)->update(['connect_organization_id' => $parents[$route->map_id]]);
                }
            }
        });
    }

    // Corrected data ownership remains intact when rolling back code.
    public function down(): void {}
};
