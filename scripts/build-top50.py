#!/usr/bin/env python3
"""Build a permanent local XYZ/WebP pyramid from the official TOP50 GeoTIFF ZIP.

Run with a dedicated venv containing rasterio and Pillow. No network requests
are made by this converter. The ZIP must already be downloaded and complete.
"""
import argparse
import json
import math
import os
import time
import zipfile
from pathlib import Path
import xml.etree.ElementTree as ET

import numpy as np
import rasterio
from PIL import Image
from rasterio.enums import Resampling
from rasterio.transform import from_origin
from rasterio.vrt import WarpedVRT
from rasterio.windows import Window

M = 20037508.342789244


def index(lon, lat, zoom):
    n = 2 ** zoom
    return (min(n - 1, max(0, int((lon + 180) / 360 * n))),
            min(n - 1, max(0, int((1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * n))))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--reverse-x', action='store_true', help='Process east to west; may run alongside the normal resumable build')
    parser.add_argument('--start-x', type=int, help='Start at this z14 column, then wrap around; may run alongside other builders')
    args = parser.parse_args()
    source = args.root / 'storage/app/top50-source'
    output = args.root / 'storage/app/tiles/pdok/top50'
    source.mkdir(parents=True, exist_ok=True)
    output.mkdir(parents=True, exist_ok=True)
    archive = source / 'top50.zip.part'
    status_path = source / 'status.json'
    started = time.time()

    def status(**values):
        values.update(elapsed_seconds=round(time.time() - started))
        temp = status_path.with_suffix(f'.{os.getpid()}.tmp')
        temp.write_text(json.dumps(values, indent=2))
        temp.replace(status_path)
        print(json.dumps(values), flush=True)

    status(state='checking_archive')
    with zipfile.ZipFile(archive) as zf:
        members = [i for i in zf.infolist() if i.filename.lower().endswith(('.tif', '.tiff'))]
        if not members:
            raise RuntimeError('No GeoTIFF files found')
        for member in members:
            target = source / 'sheets' / Path(member.filename).name
            target.parent.mkdir(exist_ok=True)
            if not target.exists() or target.stat().st_size != member.file_size:
                with zf.open(member) as incoming, target.with_suffix('.part').open('wb') as dest:
                    import shutil
                    shutil.copyfileobj(incoming, dest)
                target.with_suffix('.part').replace(target)
    files = sorted((source / 'sheets').glob('*.tif'))
    info = []
    for path in files:
        with rasterio.open(path) as dataset:
            if dataset.crs is None and path.name.lower().startswith('legenda'):
                continue
            if dataset.crs.to_epsg() != 28992 or dataset.count != 1:
                raise RuntimeError(f'Unexpected source format: {path}')
            info.append((path, dataset.bounds, dataset.width, dataset.height, dataset.transform))
    left = min(i[1].left for i in info)
    right = max(i[1].right for i in info)
    bottom = min(i[1].bottom for i in info)
    top = max(i[1].top for i in info)
    res = info[0][4].a
    width, height = round((right - left) / res), round((top - bottom) / res)
    vrt = ET.Element('VRTDataset', rasterXSize=str(width), rasterYSize=str(height))
    ET.SubElement(vrt, 'SRS').text = 'EPSG:28992'
    ET.SubElement(vrt, 'GeoTransform').text = f'{left},{res},0,{top},0,{-res}'
    # Expand each sheet's own palette into RGB before warping, so colour
    # tables can differ between sheets without changing the resulting map.
    for band, color in enumerate(('Red', 'Green', 'Blue'), 1):
        target_band = ET.SubElement(vrt, 'VRTRasterBand', dataType='Byte', band=str(band))
        ET.SubElement(target_band, 'ColorInterp').text = color
        for path, bounds, sw, sh, transform in info:
            node = ET.SubElement(target_band, 'ComplexSource')
            ET.SubElement(node, 'SourceFilename', relativeToVRT='0').text = str(path)
            ET.SubElement(node, 'SourceBand').text = '1'
            ET.SubElement(node, 'SrcRect', xOff='0', yOff='0', xSize=str(sw), ySize=str(sh))
            ET.SubElement(node, 'DstRect', xOff=str(round((bounds.left-left)/res)),
                          yOff=str(round((top-bounds.top)/res)), xSize=str(sw), ySize=str(sh))
            ET.SubElement(node, 'ColorTableComponent').text = str(band)
    # A fourth band marks actual source coverage, including valid white areas.
    alpha = ET.SubElement(vrt, 'VRTRasterBand', dataType='Byte', band='4')
    ET.SubElement(alpha, 'ColorInterp').text = 'Alpha'
    for path, bounds, sw, sh, transform in info:
        node = ET.SubElement(alpha, 'ComplexSource')
        ET.SubElement(node, 'SourceFilename', relativeToVRT='0').text = str(path)
        ET.SubElement(node, 'SourceBand').text = '1'
        ET.SubElement(node, 'SrcRect', xOff='0', yOff='0', xSize=str(sw), ySize=str(sh))
        ET.SubElement(node, 'DstRect', xOff=str(round((bounds.left-left)/res)),
                      yOff=str(round((top-bounds.top)/res)), xSize=str(sw), ySize=str(sh))
        ET.SubElement(node, 'ScaleOffset').text = '255'
        ET.SubElement(node, 'ScaleRatio').text = '0'
    vrt_path = source / 'mosaic.vrt'
    ET.ElementTree(vrt).write(vrt_path, encoding='utf-8', xml_declaration=True)
    manifest = {'source': 'PDOK / Kadaster TOP50raster', 'license': 'CC-BY-4.0',
                'tile_size': 256, 'quality': 90, 'zooms': list(range(15)), 'sheets': len(info)}
    total = 0
    total_bytes = 0
    with rasterio.Env(GDAL_CACHEMAX=128, GDAL_NUM_THREADS='1'), rasterio.open(vrt_path) as dataset:
        # Warp only the most detailed level. Lower levels come from local
        # children, avoiding a whole-country source read for one small tile.
        for zoom in [14]:
            n = 2 ** zoom
            pixel = 2 * M / (256 * n)
            x0, y1 = index(2.5, 50.2, zoom)
            x1, y0 = index(7.8, 54.0, zoom)
            with WarpedVRT(dataset, crs='EPSG:3857', transform=from_origin(-M, M, pixel, pixel),
                           width=n*256, height=n*256, resampling=Resampling.bilinear,
                           warp_mem_limit=64) as warped:
                blocks = list(range(x0, x1+1, 4))
                if args.start_x is not None:
                    split = next((i for i, x in enumerate(blocks) if x >= args.start_x), 0)
                    blocks = blocks[split:] + blocks[:split]
                for bx in (reversed(blocks) if args.reverse_x else blocks):
                    for by in range(y0, y1+1, 4):
                        bw, bh = min(4, x1-bx+1), min(4, y1-by+1)
                        paths = [output / str(zoom) / str(x) / f'{y}.webp'
                                 for x in range(bx,bx+bw) for y in range(by,by+bh)]
                        if all(p.exists() for p in paths):
                            total += len(paths)
                            total_bytes += sum(p.stat().st_size for p in paths)
                            continue
                        data = warped.read(window=Window(bx*256,by*256,bw*256,bh*256))
                        for dx in range(bw):
                            directory = output / str(zoom) / str(bx+dx)
                            directory.mkdir(parents=True, exist_ok=True)
                            for dy in range(bh):
                                path = directory / f'{by+dy}.webp'
                                if not path.exists():
                                    pixels = np.moveaxis(data[:,dy*256:(dy+1)*256,dx*256:(dx+1)*256],0,2)
                                    temp = path.with_suffix(f'.{os.getpid()}.tmp')
                                    Image.fromarray(pixels).save(temp, 'WEBP', quality=90, method=4)
                                    os.replace(temp,path)
                                total += 1
                                total_bytes += path.stat().st_size
                    status(state='converting', zoom=zoom, x=bx, x_end=x1, tiles=total, bytes=total_bytes)
            status(state='zoom_complete', zoom=zoom, tiles=total, bytes=total_bytes)
    for zoom in range(13, -1, -1):
        x0, y1 = index(2.5, 50.2, zoom)
        x1, y0 = index(7.8, 54.0, zoom)
        columns = list(range(x0, x1+1))
        for x in (reversed(columns) if args.reverse_x else columns):
            directory = output / str(zoom) / str(x)
            directory.mkdir(parents=True, exist_ok=True)
            for y in range(y0, y1+1):
                path = directory / f'{y}.webp'
                if not path.exists():
                    image = Image.new('RGBA', (512,512))
                    for dx in range(2):
                        for dy in range(2):
                            child = output / str(zoom+1) / str(x*2+dx) / f'{y*2+dy}.webp'
                            if child.exists():
                                with Image.open(child) as tile:
                                    image.paste(tile.convert('RGBA'), (dx*256,dy*256))
                    temp = path.with_suffix(f'.{os.getpid()}.tmp')
                    image.resize((256,256), Image.Resampling.LANCZOS).save(temp,'WEBP',quality=90,method=4)
                    os.replace(temp,path)
                total += 1
                total_bytes += path.stat().st_size
            status(state='converting',zoom=zoom,x=x,x_end=x1,tiles=total,bytes=total_bytes)
        status(state='zoom_complete',zoom=zoom,tiles=total,bytes=total_bytes)
    manifest.update(tiles=total, bytes=total_bytes)
    (output / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    status(state='complete', **manifest)


if __name__ == '__main__':
    main()
