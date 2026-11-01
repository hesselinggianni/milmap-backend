<?php

namespace Tests\Feature;

use App\Http\Controllers\PdokTileController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * De NL-stafkaart als één doorlopende laag ("stafkaart"): per even zoomniveau het passende
 * TOPraster-product, binnen de schaalgrenzen van de dienst, als één tegelbron voor de app.
 */
class StafkaartTileTest extends TestCase
{
    private string $temporary;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->temporary = sys_get_temp_dir() . '/milmap-stafkaart-test-' . bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->temporary);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    public function test_every_zoom_level_uses_a_product_that_is_valid_at_that_scale(): void
    {
        // Schaalgrenzen (noemer) uit de GetCapabilities van service.pdok.nl/kadaster/brt-topraster.
        $limits = [
            'top25raster' => [4000, 50000],
            'top50raster' => [12000, 100000],
            'top100raster' => [24000, 200000],
            'top250raster' => [60000, 500000],
            'top500raster' => [120000, 1000000],
            'top1000raster' => [240000, 2200000],
        ];

        foreach (PdokTileController::STAFKAART_PRODUCTS as $z => [$layer, $px]) {
            // Aangevraagde pixels bepalen de schaal: 0,28 mm/px op NL-breedte (cos 52,1°).
            $metersPerPixel = 156543.034 * cos(deg2rad(52.1)) / (2 ** $z) * (256 / $px);
            $scale = $metersPerPixel / 0.00028;
            [$min, $max] = $limits[$layer];
            $this->assertGreaterThanOrEqual($min, $scale, "z{$z} ({$layer}) is te ver ingezoomd voor het product");
            $this->assertLessThanOrEqual($max, $scale, "z{$z} ({$layer}) is te ver uitgezoomd voor het product");
        }
    }

    public function test_the_layer_covers_all_even_levels_from_its_minimum_to_its_maximum(): void
    {
        $def = PdokTileController::LAYERS['stafkaart'];
        for ($z = $def['minZoom']; $z <= $def['maxZoom']; $z += 2) {
            $this->assertArrayHasKey($z, PdokTileController::STAFKAART_PRODUCTS, "z{$z} mist een product");
        }
        $this->assertSame(6, $def['minZoom']);
        $this->assertSame(16, $def['maxZoom']);
    }

    public function test_the_upstream_request_picks_the_product_for_the_zoom_level(): void
    {
        [, $q8] = PdokTileController::upstreamRequest('stafkaart', 8, 131, 84);
        $this->assertSame('top1000raster', $q8['LAYERS']);
        $this->assertSame(256, $q8['WIDTH']);

        [, $q14] = PdokTileController::upstreamRequest('stafkaart', 14, 8434, 5393);
        $this->assertSame('top25raster', $q14['LAYERS']);

        // z6 ligt boven de schaalgrens van elk product: 1024 px opvragen en terugschalen.
        [, $q6] = PdokTileController::upstreamRequest('stafkaart', 6, 33, 21);
        $this->assertSame('top1000raster', $q6['LAYERS']);
        $this->assertSame(1024, $q6['WIDTH']);
        $this->assertSame(1024, $q6['HEIGHT']);
    }

    public function test_the_other_layers_still_request_their_own_product(): void
    {
        [, $top25] = PdokTileController::upstreamRequest('top25', 14, 8434, 5393);
        $this->assertSame('top25raster', $top25['LAYERS']);
        $this->assertSame(256, $top25['WIDTH']);
    }

    public function test_z6_tiles_are_scaled_back_to_256_pixels(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD ontbreekt.');
        }
        $big = imagecreatetruecolor(1024, 1024);
        ob_start();
        imagepng($big);
        $png = (string) ob_get_clean();

        $out = PdokTileController::normalize('stafkaart', 6, $png);
        $size = getimagesizefromstring($out);
        $this->assertSame([256, 256], [$size[0], $size[1]]);

        // Vanaf z8 en bij andere lagen blijft de tegel zoals hij is.
        $this->assertSame($png, PdokTileController::normalize('stafkaart', 8, $png));
        $this->assertSame($png, PdokTileController::normalize('top25', 14, $png));
    }

    public function test_levels_below_the_minimum_are_blank_and_never_hit_upstream(): void
    {
        Http::fake();
        $response = (new PdokTileController)->show('stafkaart', 4, 8, 5);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        Http::assertNothingSent();
    }

    public function test_a_tile_is_fetched_once_and_then_served_from_disk(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        Http::fake(['*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        [$x, $y] = PdokTileController::tileIndex(5.12, 52.09, 12);

        $first = (new PdokTileController)->show('stafkaart', 12, $x, $y);
        $this->assertSame(200, $first->getStatusCode());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ($request['LAYERS'] ?? null) === 'top100raster');

        (new PdokTileController)->show('stafkaart', 12, $x, $y);
        Http::assertSentCount(1);
    }
}
