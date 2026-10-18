#!/usr/bin/env python3
"""Check the complete permanent TOP50 pyramid before publishing it."""
import json
import math
import subprocess
from pathlib import Path

from PIL import Image

root = Path(__file__).resolve().parents[1]
tiles = root / 'storage/app/tiles/pdok/top50'
manifest = json.loads((tiles / 'manifest.json').read_text())


def index(lon, lat, zoom):
    n = 2 ** zoom
    return (min(n - 1, max(0, int((lon + 180) / 360 * n))),
            min(n - 1, max(0, int((1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * n))))


count = 0
size = 0
for zoom in range(15):
    x0, y1 = index(2.5, 50.2, zoom)
    x1, y0 = index(7.8, 54.0, zoom)
    for x in range(x0, x1 + 1):
        for y in range(y0, y1 + 1):
            path = tiles / str(zoom) / str(x) / f'{y}.webp'
            stat = path.stat()  # Missing files fail verification.
            assert stat.st_size > 0, path
            size += stat.st_size
            count += 1
assert count == manifest['tiles'] == 91695
assert size == manifest['bytes']
samples = 0
for lon, lat in [(4.9, 52.37), (4.48, 51.92), (5.12, 52.09), (6.57, 53.22),
                 (5.69, 50.85), (5.75, 52.2), (6.5, 52.8), (3.57, 51.5)]:
    for zoom in [0, 6, 8, 10, 12, 13, 14]:
        x, y = index(lon, lat, zoom)
        path = tiles / str(zoom) / str(x) / f'{y}.webp'
        with Image.open(path) as image:
            image.load()
            assert image.format == 'WEBP' and image.size == (256, 256), path
            assert image.convert('RGBA').getextrema()[3][1] > 0, path
            if zoom >= 12:
                assert len(image.convert('RGB').getcolors(65536)) > 8, path
            samples += 1
allocated = int(subprocess.check_output(['du', '-s', '-B1', str(tiles)]).split()[0])
print(json.dumps({'verified_tiles': count, 'decoded_samples': samples,
                  'webp_bytes': size, 'allocated_bytes': allocated,
                  'webp_GB': round(size / 1e9, 3),
                  'disk_GB': round(allocated / 1e9, 3)}, indent=2))
