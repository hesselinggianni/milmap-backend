<?php

namespace Tests\Feature;

use App\Http\Controllers\MapboxOverviewTileController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapboxOverviewTilesTest extends TestCase
{
    private string $tileStorage;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->tileStorage = sys_get_temp_dir() . '/milmap-overview-' . bin2hex(random_bytes(8));
        $this->app->useStoragePath($this->tileStorage);
        $this->withoutMiddleware();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->tileStorage);
        parent::tearDown();
    }

    public function test_globe_and_overview_tiles_are_served_locally_without_mapbox_token(): void
    {
        config(['services.mapbox.token' => null]);
        foreach ([0, 2, 6] as $z) {
            $path = MapboxOverviewTileController::cachePath('outdoors-v12', 'nl', $z, 0, 0);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'local-webp-fixture');
            $this->get("/api/v1/tiles/mapbox-overview/outdoors-v12/nl/{$z}/0/0")
                ->assertOk()->assertHeader('Content-Type', 'image/webp');
        }
        Http::assertNothingSent();
    }

    public function test_invalid_coordinates_and_retired_styles_do_not_reach_upstream(): void
    {
        foreach (['outdoors-v12/nl/7/0/0', 'outdoors-v12/nl/10/0/0', 'outdoors-v12/nl/2/4/0', 'dark-v11/nl/2/0/0', 'light-v11/nl/2/0/0', 'outdoors-v12/invalid/2/0/0'] as $suffix) {
            $this->get('/api/v1/tiles/mapbox-overview/' . $suffix)->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_styles_and_languages_have_separate_cache_paths(): void
    {
        $base = MapboxOverviewTileController::cachePath('outdoors-v12', 'nl', 2, 0, 0);
        $this->assertNotEquals($base, MapboxOverviewTileController::cachePath('streets-v11', 'nl', 2, 0, 0));
        $this->assertNotEquals($base, MapboxOverviewTileController::cachePath('outdoors-v12', 'en', 2, 0, 0));
    }

    public function test_missing_tiles_report_unavailable_when_no_token_is_configured(): void
    {
        config(['services.mapbox.token' => null]);
        $this->get('/api/v1/tiles/mapbox-overview/outdoors-v12/nl/0/0/0')->assertStatus(503);
        Http::assertNothingSent();
    }
    public function test_english_is_never_fetched_or_served_by_the_dutch_proxy(): void
    {
        $this->get('/api/v1/tiles/mapbox-overview/outdoors-v12/en/0/0/0')->assertNotFound();
        $this->get('/api/v1/tiles/mapbox-overview/outdoors-v12/en/9/0/0')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_zooms_above_six_are_not_served_or_fetched_by_our_server(): void
    {
        foreach ([7, 8, 9, 10] as $z) {
            $this->get("/api/v1/tiles/mapbox-overview/outdoors-v12/nl/{$z}/0/0")->assertNotFound();
        }
        Http::assertNothingSent();
    }
}
