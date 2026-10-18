<?php

namespace Tests\Feature;

use App\Http\Controllers\PdokTileController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalTop50Test extends TestCase
{
    private string $temporary;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->temporary = sys_get_temp_dir() . '/milmap-top50-test-' . bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->temporary);
        Http::fake();
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    public function test_old_local_tiles_are_served_without_upstream_requests(): void
    {
        [$x, $y] = PdokTileController::tileIndex(5.12, 52.09, 13);
        $file = PdokTileController::cacheBase('top50', 13, $x, $y) . '.webp';
        File::ensureDirectoryExists(dirname($file));
        File::put($file, 'local-tile');
        touch($file, now()->subYear()->getTimestamp());
        $response = (new PdokTileController)->show('top50', 13, $x, $y);
        $this->assertSame($file, $response->getFile()->getPathname());
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        Http::assertNothingSent();
    }

    public function test_missing_tiles_never_trigger_an_external_download(): void
    {
        [$x, $y] = PdokTileController::tileIndex(5.12, 52.09, 13);
        $response = (new PdokTileController)->show('top50', 13, $x, $y);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        Http::assertNothingSent();
    }

    public function test_pruning_preserves_the_permanent_map(): void
    {
        $top50 = PdokTileController::cacheBase('top50', 13, 4212, 2703) . '.webp';
        $top25 = PdokTileController::cacheBase('top25', 13, 4212, 2703) . '.webp';
        foreach ([$top50, $top25] as $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'test');
            touch($path, now()->subYear()->getTimestamp());
        }
        $this->artisan('tiles:prune-pdok')->assertSuccessful();
        $this->assertFileExists($top50);
        $this->assertFileDoesNotExist($top25);
    }

    public function test_preview_rejects_invalid_bounds_without_upstream_requests(): void
    {
        $this->withoutMiddleware();
        foreach (['0,0,1,1', '0,0,100,100000', 'bad,bounds'] as $bbox) {
            $this->get('/api/v1/tiles/top50-preview?bbox=' . $bbox)->assertStatus(422);
        }
        Http::assertNothingSent();
    }
}
