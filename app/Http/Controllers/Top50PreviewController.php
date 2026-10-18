<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/** KAKO thumbnails composed exclusively from the permanent local TOP50 tiles. */
class Top50PreviewController extends Controller
{
    public function show(Request $request)
    {
        $bbox = explode(',', (string) $request->query('bbox', ''));
        abort_unless(count($bbox) === 4 && count(array_filter($bbox, 'is_numeric')) === 4, 422);
        [$west, $south, $east, $north] = array_map('floatval', $bbox);
        $limit = 20037508.342789244;
        abort_unless($east > $west && $north > $south && $west >= -$limit && $east <= $limit
            && $south >= -$limit && $north <= $limit, 422);
        $ratio = ($east - $west) / ($north - $south);
        abort_unless($east - $west >= 100 && $north - $south >= 100 && $ratio >= 0.25 && $ratio <= 4, 422);
        $key = sha1(implode(',', $bbox));
        $file = storage_path("app/tiles/top50-preview/{$key}.webp");
        // Regenerate previews after a new local tile pyramid is installed.
        $manifest = storage_path('app/tiles/pdok/top50/manifest.json');
        if (is_file($file) && (!is_file($manifest) || filemtime($file) >= filemtime($manifest))) {
            return response()->file($file, ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=86400']);
        }
        $zoom = max(0, min(14, (int) floor(log(2 * $limit * 640 / (256 * ($east - $west)), 2))));
        $span = 2 * $limit / (2 ** $zoom);
        $x0 = max(0, (int) floor(($west + $limit) / $span));
        $x1 = min(2 ** $zoom - 1, (int) floor(($east + $limit) / $span));
        $y0 = max(0, (int) floor(($limit - $north) / $span));
        $y1 = min(2 ** $zoom - 1, (int) floor(($limit - $south) / $span));
        abort_unless(($x1 - $x0 + 1) * ($y1 - $y0 + 1) <= 64, 422);
        $image = imagecreatetruecolor(640, 360);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 245, 240));
        for ($x = $x0; $x <= $x1; $x++) {
            for ($y = $y0; $y <= $y1; $y++) {
                $tile = PdokTileController::cachedFile(PdokTileController::cacheBase('top50', $zoom, $x, $y));
                if (!$tile || !($source = @imagecreatefromwebp($tile))) {
                    continue;
                }
                $dx = (int) round(($x * $span - $limit - $west) / ($east - $west) * 640);
                $dy = (int) round(($north - ($limit - $y * $span)) / ($north - $south) * 360);
                $right = (int) round((($x + 1) * $span - $limit - $west) / ($east - $west) * 640);
                $bottom = (int) round(($north - ($limit - ($y + 1) * $span)) / ($north - $south) * 360);
                imagecopyresampled($image, $source, $dx, $dy, 0, 0, $right - $dx, $bottom - $dy, 256, 256);
                imagedestroy($source);
            }
        }
        File::ensureDirectoryExists(dirname($file));
        $temporary = tempnam(dirname($file), 'preview-');
        try {
            abort_unless(imagewebp($image, $temporary, 90), 500);
            rename($temporary, $file);
        } finally {
            imagedestroy($image);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return response()->file($file, ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=86400']);
    }
}
