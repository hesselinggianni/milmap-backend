#!/usr/bin/env python3
"""Install a verified zoom 0–6 cache and narrowly staged live app changes.

Run from the server backend: python3 scripts/install-mapbox-overview.py STAGE [--check]
STAGE contains MapboxOverviewTileController.php, js/, index.html, sw.js,
frontend-manifest.json. Tiles must already be present and independently verified.
"""
from datetime import datetime, timezone
import hashlib
import json
import re
from pathlib import Path
import shutil
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
stage = Path(sys.argv[1]).resolve()
checking = '--check' in sys.argv[2:]
backend_only = '--backend-only' in sys.argv[2:]
frontend = Path('/home/milmap/domains/app.milmap.nl/public_html')
manifest = json.loads((root / 'storage/app/tiles/mapbox-overview/manifest.json').read_text())
assert manifest['tiles'] == 21844 and manifest['zooms'] == list(range(7))
assert all(s['tiles'] == 5461 for s in manifest['styles'].values())
if backend_only:
    html = (frontend / 'index.html').read_text()
    active_app = re.search(r'js/(app\.[0-9a-f]+\.js)', html).group(1)
    app_text = (frontend / 'js' / active_app).read_text()
    assert '/api/v1/tiles/mapbox-overview/' in app_text, 'Live app lacks the overview routing module.'
    assert 'lx:()=>' in app_text and 'return{url:' in app_text, 'Live app lacks the MapLibre request adapter.'
else:
    meta = json.loads((stage / 'frontend-manifest.json').read_text())
    assert hashlib.sha256((frontend / 'index.html').read_bytes()).hexdigest() == meta['original_html_sha256'], 'Live shell changed; regenerate the staged patch.'
    for relative, expected in meta['original_sha256'].items():
        assert hashlib.sha256((frontend / relative).read_bytes()).hexdigest() == expected, f'Live file changed: {relative}'
controller = root / 'app/Http/Controllers/MapboxOverviewTileController.php'
routes_path = root / 'routes/api.php'
routes = routes_path.read_text()
if "Route::get('/tiles/mapbox-overview/" not in routes:
    marker = "    Route::get('/tiles/terrain/"
    assert routes.count(marker) == 1
    addition = """    Route::get('/tiles/mapbox-overview/{style}/{language}/{z}/{x}/{y}', [\\App\\Http\\Controllers\\MapboxOverviewTileController::class, 'show'])
        ->whereIn('style', \\App\\Http\\Controllers\\MapboxOverviewTileController::STYLES)
        ->where('language', '[a-z]{2}(-[A-Za-z]{2})?')
        ->whereNumber(['z', 'x', 'y'])
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:30000,1');

"""
    routes = routes.replace(marker, addition + marker, 1)
changes = {controller: (stage / controller.name).read_bytes(), routes_path: routes.encode()}
if not backend_only:
    for name in meta['assets']:
        assert '/' not in name and name.endswith('.js')
        changes[frontend / 'js' / name] = (stage / 'js' / name).read_bytes()
    for name in ['index.html', 'sw.js']:
        changes[frontend / name] = (stage / name).read_bytes()
for path, data in changes.items():
    if path.suffix == '.php':
        candidate = stage / ('check-' + path.name)
        candidate.write_bytes(data)
        subprocess.run(['php', '-l', str(candidate)], check=True)
if checking:
    print('Validated installation:', len(changes), 'files;', manifest['tiles'], 'tiles;', manifest['bytes'], 'bytes')
    sys.exit(0)
backup = Path('/home/milmap/milmap-backups') / ('mapbox-overview-' + datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S'))
backup.mkdir(parents=True)
originals = {}
for path in changes:
    originals[path] = path.read_bytes() if path.exists() else None
    if path.exists():
        destination = backup / str(path).lstrip('/')
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, destination)
def write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + '.overview-new')
    temporary.write_bytes(data)
    temporary.chmod(0o644)
    temporary.replace(path)
try:
    # Activate and validate the backend before publishing the updated frontend shell.
    for path in [controller, routes_path]:
        write(path, changes[path])
    subprocess.run(['php', 'artisan', 'route:cache'], cwd=root, check=True)
    for path, data in changes.items():
        if path not in [controller, routes_path]:
            write(path, data)
except BaseException:
    for path, data in originals.items():
        if data is None:
            path.unlink(missing_ok=True)
        else:
            write(path, data)
    subprocess.run(['php', 'artisan', 'route:cache'], cwd=root, check=False)
    raise
print('Installed:', manifest['tiles'], 'tiles;', manifest['bytes'], 'bytes')
print('Backup:', backup)
