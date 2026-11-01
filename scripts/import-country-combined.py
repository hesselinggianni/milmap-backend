#!/usr/bin/env python3
"""Hervat één gecombineerde landenexport en publiceer die na volledige controle."""

import argparse
import fcntl
import importlib.util
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run_import(folder, dry_run=False):
    command = ['php', 'artisan', 'milmap:import-routes', str(folder), '--no-ansi', '--no-interaction']
    if dry_run:
        command.append('--dry-run')
    result = subprocess.run(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    print(result.stdout, flush=True)
    rows = re.findall(r'^\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*$', result.stdout, re.M)
    if result.returncode or not rows or int(rows[-1][-1]):
        raise RuntimeError('Artisan-import mislukt of bevat afgekeurde routes')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--country', required=True)
    parser.add_argument('--types', nargs='+', required=True)
    parser.add_argument('--exporter', default='milmap_routes_europe.py')
    parser.add_argument('--out', required=True)
    args = parser.parse_args()

    folder = ROOT / args.out
    folder.mkdir(parents=True, exist_ok=True)
    lock = (folder / 'combined-runner.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

    exporter = ROOT / args.exporter
    spec = importlib.util.spec_from_file_location('osm_exporter', exporter)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    if args.country not in module.COUNTRIES:
        raise ValueError('Onbekend land')
    if any(kind not in module.PROFILES for kind in args.types):
        raise ValueError('Onbekend routetype')

    subprocess.run([
        sys.executable, '-u', str(exporter), '--country', args.country,
        '--types', *args.types, '--count', '10000', '--out', str(folder),
    ], cwd=ROOT, check=True)

    country_code, regions = module.COUNTRIES[args.country]
    expected = {
        f'{country_code.lower()}-{kind}-{region}.json'
        for kind in args.types for region in regions
    }
    caches = {path.name: path for path in (folder / '.cache').glob('*.json')}
    missing = expected - caches.keys()
    incomplete = [name for name in expected & caches.keys() if not json.loads(caches[name].read_text()).get('complete')]
    if missing or incomplete:
        raise RuntimeError(f'Export onvolledig: {len(missing)} ontbrekend, {len(incomplete)} niet afgerond')

    manifest = json.loads((folder / 'routes.json').read_text())
    ids = set()
    for route in manifest.get('routes', []):
        if route['country'] != country_code or route['type'] not in args.types:
            raise RuntimeError('Onverwacht land of routetype in manifest')
        if route['osm_relation'] in ids:
            raise RuntimeError('Dubbele OSM-relatie in manifest')
        ids.add(route['osm_relation'])
        gpx = (folder / route['gpx_file']).resolve()
        if not gpx.is_relative_to(folder.resolve()) or not gpx.is_file():
            raise RuntimeError('GPX-bestand ontbreekt of staat buiten exportmap')

    print(f'Gevalideerd: {len(ids)} unieke routes', flush=True)
    run_import(folder, dry_run=True)
    run_import(folder)
    print(f'LIVE: {len(ids)} routes voor {country_code}', flush=True)
    return 0


if __name__ == '__main__':
    sys.exit(main())
