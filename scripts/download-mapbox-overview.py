#!/usr/bin/env python3
"""Download global Mapbox raster zooms 0–6 as WebP. Called by tiles:warm-mapbox-overview.
Requires Python 3 + Pillow; token is passed through the environment, never logged.
"""
import argparse
from collections import defaultdict
import json
from concurrent.futures import ThreadPoolExecutor
import io
import os
from pathlib import Path
import re
import random
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import urlopen
from PIL import Image

STYLES = ('outdoors-v12', 'streets-v11', 'satellite-v9', 'satellite-streets-v11')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--style', action='append', choices=STYLES)
    parser.add_argument('--language', default='nl')
    parser.add_argument('--concurrency', type=int, default=8)
    parser.add_argument('--max-zoom', type=int, default=6, choices=range(0, 7))
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--estimate', action='store_true', help='Stratified global sample: 64 per zoom, 128 at zoom 7 and higher')
    args = parser.parse_args()
    if not re.fullmatch(r'[a-z]{2}(?:-[A-Za-z]{2})?', args.language):
        parser.error('Invalid language')
    token = os.environ.get('MAPBOX_TOKEN', '')
    if not token and not args.dry_run:
        parser.error('MAPBOX_TOKEN is missing')
    styles = args.style or list(STYLES)
    cutoff = time.time() - 30 * 86400
    jobs = []
    samples = defaultdict(list)
    for style in styles:
        for z in range(args.max_zoom + 1):
            missing = 0
            side = 2 ** z
            if args.estimate and z >= 7:
                # Jitter within 16x8 equal-area cells in tile space; deterministic
                # across styles for a fair comparison, avoiding systematic coast bias.
                rng = random.Random(20261018 + z)
                coordinates = [(cx * (side // 16) + rng.randrange(side // 16),
                                cy * (side // 8) + rng.randrange(side // 8))
                               for cx in range(16) for cy in range(8)]
            elif args.estimate and z > 3:
                stride = side // 8
                coordinates = ((x, y) for x in range(stride // 2, side, stride)
                               for y in range(stride // 2, side, stride))
            else:
                coordinates = ((x, y) for x in range(side) for y in range(side))
            for x, y in coordinates:
                path = args.output / style / args.language / str(z) / str(x) / f'{y}.webp'
                if path.is_file() and path.stat().st_mtime > cutoff:
                    samples[(style, z)].append(path.stat().st_size)
                    continue
                jobs.append((style, z, x, y, path))
                missing += 1
            print(f'{style} {args.language} z{z}: {missing} to download', flush=True)
    print(f'Total: {len(jobs)} tiles', flush=True)
    if args.dry_run:
        return 0

    def download(job):
        style, z, x, y, path = job
        url = f'https://api.mapbox.com/styles/v1/mapbox/{style}/tiles/512/{z}/{x}/{y}?'
        url += urlencode({'access_token': token, 'language': args.language})
        for attempt in range(3):
            try:
                with urlopen(url, timeout=30) as response:
                    data = response.read()
                with Image.open(io.BytesIO(data)) as image:
                    if image.size != (512, 512):
                        raise ValueError('Unexpected tile dimensions')
                    path.parent.mkdir(parents=True, exist_ok=True)
                    temporary = None
                    try:
                        with tempfile.NamedTemporaryFile(dir=path.parent, suffix='.webp', delete=False) as output:
                            temporary = output.name
                            image.save(output, 'WEBP', quality=90, method=4)
                        os.chmod(temporary, 0o644)
                        os.replace(temporary, path)
                    finally:
                        if temporary and os.path.exists(temporary):
                            os.unlink(temporary)
                return path.stat().st_size
            except HTTPError as error:
                if error.code in (401, 403):
                    raise RuntimeError(f'Mapbox denied access (HTTP {error.code})') from None
                if attempt == 2:
                    print(f'FAILED {style}/{z}/{x}/{y}: HTTP {error.code}', flush=True)
            except Exception as error:
                if isinstance(error, RuntimeError):
                    raise
                if attempt == 2:
                    # Avoid printing exceptions containing authenticated request URLs.
                    print(f'FAILED {style}/{z}/{x}/{y}: {type(error).__name__}', flush=True)
            time.sleep(2 ** attempt)
        return None

    success = 0
    with ThreadPoolExecutor(max_workers=max(1, min(16, args.concurrency))) as executor:
        for completed, ok in enumerate(executor.map(download, jobs), 1):
            success += ok is not None
            if ok is not None:
                style, z, *_ = jobs[completed - 1]
                samples[(style, z)].append(ok)
            if completed % 100 == 0 or completed == len(jobs):
                print(f'{completed}/{len(jobs)} processed; {success} saved', flush=True)
    if args.estimate and success == len(jobs):
        estimates = {}
        for style in styles:
            total = sum(sum(samples[(style, z)]) / len(samples[(style, z)]) * 4 ** z for z in range(args.max_zoom + 1))
            globe = sum(sum(samples[(style, z)]) for z in range(min(2, args.max_zoom + 1)))
            estimates[style] = {'tiles': sum(4 ** z for z in range(args.max_zoom + 1)), 'estimated_bytes': round(total),
                                'globe_z0_z1_bytes': globe, 'sample_tiles': sum(len(samples[(style, z)]) for z in range(args.max_zoom + 1)),
                                'estimated_bytes_by_zoom': {str(z): round(sum(samples[(style, z)]) / len(samples[(style, z)]) * 4 ** z) for z in range(args.max_zoom + 1)}}
        report = {'max_zoom': args.max_zoom, 'language': args.language, 'quality': 90, 'styles': estimates,
                  'estimated_total_bytes': sum(s['estimated_bytes'] for s in estimates.values())}
        args.output.mkdir(parents=True, exist_ok=True)
        (args.output / ('size-estimate.json' if args.max_zoom == 6 else f'size-estimate-z{args.max_zoom}.json')).write_text(json.dumps(report, indent=2))
        print(json.dumps(report, indent=2), flush=True)
    return 0 if success == len(jobs) else 1


if __name__ == '__main__':
    raise SystemExit(main())
