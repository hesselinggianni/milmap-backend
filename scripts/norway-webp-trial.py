"""Measure N50 native UTM raster tiles. Not an XYZ/Web Mercator tile export.
Usage: python3 scripts/norway-webp-trial.py SOURCE.tif OUTPUT_DIRECTORY
Requires Pillow and numpy. Source attribution: © Kartverket, CC BY 4.0.
"""
import json
import math
import sys
from pathlib import Path
from PIL import Image
import numpy as np

source, output = Path(sys.argv[1]), Path(sys.argv[2])
output.mkdir(parents=True, exist_ok=True)
im = Image.open(source).convert('RGB')
original = Image.open(source)
scale = original.tag_v2.get(33550)
area = im.width * im.height * scale[0] * scale[1] / 1e6 if scale else None
report = {'source': source.name, 'source_bytes': source.stat().st_size,
          'size': list(im.size), 'pixel_metres': list(scale[:2]) if scale else None,
          'area_km2': area, 'attribution': '© Kartverket',
          'grid': 'native EPSG:25833, local pyramid indices; NOT Web Mercator XYZ', 'variants': {}}
preview = Image.new('RGB', (1536, 560), 'white')
from PIL import ImageDraw
for col, variant in enumerate(['original', 'lossless', 'q85']):
    crop = im.crop((3500,3500,4012,4012))
    if variant != 'original':
        crop.save(output / f'preview-{variant}.webp', 'WEBP', lossless=variant=='lossless', quality=85, method=6)
        crop = Image.open(output / f'preview-{variant}.webp').convert('RGB')
    preview.paste(crop, (col*512,40))
    ImageDraw.Draw(preview).text((col*512+10,12), variant+' | © Kartverket', fill='black')
preview.save(output/'comparison.webp', 'WEBP', lossless=True)
for variant in ['lossless', 'q85']:
    total = count = 0
    squared_error = 0.0
    samples = 0
    level = im
    z = 0
    while True:
        folder = output/variant/str(z)
        folder.mkdir(parents=True, exist_ok=True)
        for y in range(0,level.height,256):
            for x in range(0,level.width,256):
                tile = level.crop((x,y,min(x+256,level.width),min(y+256,level.height)))
                path = folder/f'{x//256}-{y//256}.webp'
                tile.save(path,'WEBP',lossless=variant=='lossless',quality=85,method=4)
                decoded = Image.open(path)
                assert decoded.format == 'WEBP' and decoded.size == tile.size
                a = np.asarray(tile).astype(np.float32)
                b = np.asarray(decoded.convert('RGB')).astype(np.float32)
                error = float(np.sum((a-b)**2))
                if variant=='lossless': assert error==0
                squared_error += error
                samples += a.size
                total += path.stat().st_size
                count += 1
        if max(level.size)<=256: break
        level = level.resize(((level.width+1)//2,(level.height+1)//2), Image.Resampling.LANCZOS)
        z += 1
    mse = squared_error/samples
    report['variants'][variant] = {'bytes': total, 'tiles': count, 'levels':z+1,
        'mean_tile_bytes': total/count, 'psnr_db':10*math.log10(255**2/mse) if mse else None,
        'gb_per_324000_km2': total/area*324000/1e9 if area else None}
(output/'report.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
