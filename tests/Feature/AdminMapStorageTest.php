<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdminMapStorageTest extends TestCase
{
    private string $original;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = storage_path();
        $this->directory = sys_get_temp_dir() . '/milmap-map-status-' . bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->directory);
        File::ensureDirectoryExists(storage_path('app/tiles/mapbox-overview/outdoors-v12/nl'));
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->original);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function writeManifest(string $name, array $data): void
    {
        file_put_contents(storage_path('app/tiles/mapbox-overview/' . $name), json_encode($data));
    }

    public function test_route_is_admin_protected(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/v1/admin/map-storage'));
        $this->assertContains('admin.auth', $route->gatherMiddleware());
    }

    public function test_overview_maps_report_only_zoom_zero_to_six_and_no_download(): void
    {
        $this->withoutMiddleware();
        $this->writeManifest('manifest.json', ['quality' => 90, 'zooms' => range(0, 6), 'styles' => ['outdoors-v12' => ['tiles' => 5461, 'bytes' => 70000000]]]);
        $response = $this->getJson('/api/v1/admin/map-storage?refresh=1')->assertOk()
            ->assertHeader('Pragma', 'no-cache')->assertJsonPath('maps.0.complete_zooms', range(0, 6))
            ->assertJsonPath('maps.0.partial_zooms', [])->assertJsonPath('maps.0.target_zooms', range(0, 6))
            ->assertJsonPath('maps.0.download', null)->assertJsonPath('maps.0.quality', 90);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
