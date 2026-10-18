#!/usr/bin/env python3
"""Targeted production install from a staged directory, preserving other work.

Usage: python3 scripts/install-top50.py /path/to/stage
Stage contains PdokTileController.php, Top50PreviewController.php, TileWebp.php.
The complete local pyramid must exist before this installer is run.
"""
import hashlib
import json
import re
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

root = Path(__file__).resolve().parents[1]
stage = Path(sys.argv[1]).resolve()
checking = '--check' in sys.argv[2:]
frontend = Path('/home/milmap/domains/app.milmap.nl/public_html')
manifest_path = root / 'storage/app/tiles/pdok/top50/manifest.json'
manifest = json.loads(manifest_path.read_text()) if manifest_path.exists() else {}
if not checking:
    assert manifest['tiles'] > 90000 and manifest['zooms'] == list(range(15))
stamp = datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')
backup = Path('/home/milmap/milmap-backups') / ('top50_' + stamp)
changes = {}
for relative in ['app/Http/Controllers/PdokTileController.php',
                 'app/Http/Controllers/Top50PreviewController.php',
                 'app/Support/TileWebp.php']:
    changes[root / relative] = (stage / Path(relative).name).read_text()

routes_path = root / 'routes/api.php'
routes = routes_path.read_text()
if "Route::get('/tiles/pdok/" not in routes:
    anchor = "    Route::get('/tiles/terrain/"
    assert anchor in routes
    addition = """    // Permanent local TOP50 map; no upstream calls for TOP50 requests.
    Route::get('/tiles/pdok/{layer}/{z}/{x}/{y}', [\\App\\Http\\Controllers\\PdokTileController::class, 'show'])
        ->whereIn('layer', ['brt', 'top25', 'top50'])
        ->whereNumber(['z', 'x', 'y'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:30000,1');

"""
    routes = routes.replace(anchor, addition + anchor, 1)
if "Route::get('/tiles/top50-preview'" not in routes:
    anchor = "    Route::get('/tiles/pdok/"
    assert anchor in routes
    routes = routes.replace(anchor, """    Route::get('/tiles/top50-preview', [\\App\\Http\\Controllers\\Top50PreviewController::class, 'show'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:600,1');

""" + anchor, 1)
changes[routes_path] = routes
services_path = root / 'config/services.php'
services = services_path.read_text()
if "'pdok' =>" not in services:
    anchor = "    'postmark' => ["
    assert anchor in services
    services = services.replace(anchor, """    'pdok' => [
        'referer' => env('PDOK_REFERER', 'https://milmap.nl'),
        'webp_quality' => 90,
        'cwebp' => env('PDOK_CWEBP_BIN', 'cwebp'),
        'clone' => ['brt' => [0, 2, 4, 6, 8, 10, 12, 14], 'top25' => [14]],
    ],

""" + anchor, 1)
changes[services_path] = services

# Modify only the two existing production chunks; do not publish the unrelated
# uncommitted frontend work from the developer's checkout. New filenames and
# runtime references prevent immutable browser caches serving old content.
assets = {p: p.read_text() for p in (frontend / 'js').glob('*.js')}
new_assets = {}
hash_replacements = {}
for path, original in assets.items():
    updated = original.replace('top50:{minZoom:12,maxZoom:14}', 'top50:{minZoom:0,maxZoom:14}')
    updated = updated.replace(
        'https://service.pdok.nl/brt/topraster/wms/v1_0?service=WMS&version=1.3.0&request=GetMap&layers=top50raster&styles=&crs=EPSG:3857&bbox=',
        'https://api.milmap.nl/api/v1/tiles/top50-preview?bbox=')
    if updated != original:
        match = re.fullmatch(r'(.+)\.([0-9a-f]{8})\.js', path.name)
        assert match, path
        digest = hashlib.sha256(updated.encode()).hexdigest()[:8]
        target = path.with_name(f'{match[1]}.{digest}.js')
        new_assets[target] = updated
        hash_replacements[match[2]] = digest
assert len(new_assets) == 2, f'Expected two focused asset changes, found {len(new_assets)}'
html = (frontend / 'index.html').read_text()
for path, original in assets.items():
    if any(old in original for old in hash_replacements):
        updated = original
        for old, new in hash_replacements.items():
            updated = updated.replace(old, new)
        if updated != original:
            match = re.fullmatch(r'(.+)\.([0-9a-f]{8})\.js', path.name)
            assert match, path
            digest = hashlib.sha256(updated.encode()).hexdigest()[:8]
            target = path.with_name(f'{match[1]}.{digest}.js')
            new_assets[target] = updated
            html = html.replace(path.name, target.name)
for old, new in hash_replacements.items():
    html = html.replace(old, new)
changes.update(new_assets)
changes[frontend / 'index.html'] = html
sw_path = frontend / 'sw.js'
sw = sw_path.read_text()
sw, count = re.subn(r'const SHELL_VERSION = "[^"]+";', f'const SHELL_VERSION = "milmap-top50-{stamp}";', sw, count=1)
assert count == 1
changes[sw_path] = sw

# Validate staged PHP before touching production files.
for path, data in changes.items():
    if path.suffix == '.php':
        check = stage / ('check-' + path.name)
        check.write_text(data)
        subprocess.run(['php', '-l', str(check)], check=True)

if checking:
    print('Validated changes:')
    for path in changes:
        print(path)
    sys.exit(0)

backup.mkdir(parents=True)
for path, data in changes.items():
    if path.exists():
        relative = str(path).lstrip('/')
        destination = backup / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, destination)
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + '.top50-new')
    temporary.write_text(data)
    temporary.replace(path)
    print('Installed', path)
subprocess.run(['php', 'artisan', 'config:cache'], cwd=root, check=True)
subprocess.run(['php', 'artisan', 'route:cache'], cwd=root, check=True)
print('Backup:', backup)
print('Map:', json.dumps(manifest))
