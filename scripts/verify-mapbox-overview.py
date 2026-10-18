#!/usr/bin/env python3
"""Verify complete WebP zoom 0–6 coverage and write a reproducible checksum manifest."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path

STYLES = ('outdoors-v12', 'streets-v11', 'satellite-v9', 'satellite-streets-v11')
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('directory', type=Path)
parser.add_argument('--language', default='nl')
parser.add_argument('--decode', action='store_true', help='Also decode every tile with Pillow')
args = parser.parse_args()
if args.decode:
    from PIL import Image
report = {'zooms': list(range(7)), 'language': args.language, 'tile_size': 512, 'quality': 90,
          'styles': {}, 'verified_at': datetime.now(timezone.utc).isoformat()}
for style in STYLES:
    digest = hashlib.sha256()
    count = size = allocated = 0
    for z in range(7):
        for x in range(2 ** z):
            for y in range(2 ** z):
                relative = Path(style) / args.language / str(z) / str(x) / f'{y}.webp'
                path = args.directory / relative
                data = path.read_bytes()
                assert data[:4] == b'RIFF' and data[8:12] == b'WEBP', f'Invalid WebP: {relative}'
                if args.decode:
                    with Image.open(path) as image:
                        assert image.size == (512, 512), f'Invalid dimensions: {relative}'
                        image.load()
                digest.update(str(relative).encode() + b'\0' + hashlib.sha256(data).digest())
                stats = path.stat()
                count += 1
                size += stats.st_size
                allocated += stats.st_blocks * 512
    report['styles'][style] = {'tiles': count, 'bytes': size, 'allocated_bytes': allocated, 'sha256': digest.hexdigest()}
report['tiles'] = sum(s['tiles'] for s in report['styles'].values())
report['bytes'] = sum(s['bytes'] for s in report['styles'].values())
report['allocated_bytes'] = sum(s['allocated_bytes'] for s in report['styles'].values())
(args.directory / 'manifest.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report, indent=2))
