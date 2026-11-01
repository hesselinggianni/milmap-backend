#!/usr/bin/env python3
"""Serial, resumable production OSM import; run from the Laravel backend root.

python3 -u scripts/import-europe-routes.py --countries de at eng es fr
Waits for the earlier Belgian import, validates every completed regional export,
then dry-runs and imports it. Progress is stored beside the exports.
"""
import argparse
import fcntl
import importlib.util
import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def save(path, value):
    temp = path.with_suffix('.tmp')
    temp.write_text(json.dumps(value, ensure_ascii=False, indent=2))
    temp.replace(path)


def belgium_running():
    processes = subprocess.check_output(['ps', '-eo', 'args='], text=True)
    for line in processes.splitlines():
        parts = line.split()
        if len(parts) < 2:
            continue
        if Path(parts[0]).name.startswith('python') and any(Path(part).name == 'milmap_routes_be.py' for part in parts[:3]):
            return True
        if Path(parts[0]).name == 'bash' and Path(parts[1]).name in {
            'milmap-belgium-resume-20261031.sh', 'milmap-belgium-finish-20261031.sh',
        }:
            return True
    return False


def other_europe_runner_running():
    """Wacht op een oudere landenwachtrij; de huidige PID telt niet mee."""
    processes = subprocess.check_output(['ps', '-eo', 'pid=,args='], text=True)
    for line in processes.splitlines():
        parts = line.split(maxsplit=1)
        if len(parts) == 2 and int(parts[0]) not in {os.getpid(), os.getppid()} and 'scripts/import-europe-routes.py' in parts[1]:
            return True
    return False


def artisan_import(folder, dry_run=False):
    command = ['php', 'artisan', 'milmap:import-routes', str(folder), '--no-ansi', '--no-interaction']
    if dry_run:
        command.append('--dry-run')
    result = subprocess.run(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    print(result.stdout, flush=True)
    rows = re.findall(r'^\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*$', result.stdout, re.M)
    if result.returncode or not rows or int(rows[-1][-1]):
        raise RuntimeError('Artisan-import mislukt of bevat afgekeurde routes')
    return dict(zip(['created', 'updated', 'unchanged', 'failed'], map(int, rows[-1])))


def validate(folder, code, region, kinds):
    states = list((folder / '.cache').glob('*.json'))
    if len(states) != len(kinds) or not all(json.loads(p.read_text()).get('complete') for p in states):
        raise RuntimeError(f'Onvolledige export voor {code}/{region}')
    routes = json.loads((folder / 'routes.json').read_text())['routes']
    ids = set()
    for route in routes:
        if route['country'] != code or route['province'] != region or route['type'] not in kinds:
            raise RuntimeError('Onverwacht land, regio of sport in export')
        if route['osm_relation'] in ids:
            raise RuntimeError('Dubbele OSM-relatie in export')
        ids.add(route['osm_relation'])
        path = (folder / route['gpx_file']).resolve()
        if not path.is_relative_to(folder.resolve()) or not path.is_file():
            raise RuntimeError('GPX-bestand ontbreekt of staat buiten exportmap')
    return len(routes)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--countries', nargs='+', default=['de', 'at', 'eng', 'es', 'fr'])
    parser.add_argument('--types', nargs='+', default=['mtb', 'wandelen', 'hardlopen'])
    parser.add_argument('--wait-for-other-runner', action='store_true')
    parser.add_argument('--exporter', default='milmap_routes_europe.py')
    parser.add_argument('--out', default='storage/app/imports/europe-20261031')
    args = parser.parse_args()
    base = ROOT / args.out
    base.mkdir(parents=True, exist_ok=True)
    lock = (base / 'runner.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    exporter = ROOT / args.exporter
    spec = importlib.util.spec_from_file_location('osm_exporter', exporter)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    if any(c not in module.COUNTRIES for c in args.countries):
        raise ValueError('Onbekend land')
    kinds = args.types
    if any(kind not in module.PROFILES for kind in kinds):
        raise ValueError('Onbekend routetype')
    status_path = base / 'status.json'
    state = json.loads(status_path.read_text()) if status_path.exists() else {'completed': {}, 'failed': {}}
    state.update(status='waiting', countries=args.countries, types=kinds)
    save(status_path, state)
    print('Wachten op Belgische import om gelijktijdige imports te voorkomen', flush=True)
    while belgium_running():
        time.sleep(15)
    if args.wait_for_other_runner:
        print('Wachten op de eerdere Europese landenwachtrij', flush=True)
        while other_europe_runner_running():
            time.sleep(15)
    jobs = [(c, code, region) for c in args.countries for code, region, _ in module.selected_regions([c], True)]
    for attempt in range(2):
        for country, code, region in jobs:
            key = f'{country}/{region}'
            if key in state['completed']:
                continue
            state.update(status='running', current=key, attempt=attempt + 1)
            save(status_path, state)
            print(f'\nSTART {key}', flush=True)
            folder = base / country / region
            try:
                subprocess.run([
                    sys.executable, '-u', str(exporter), '--country', country,
                    '--regions', region, '--types', *kinds, '--count', '1000000', '--out', str(folder),
                ], cwd=ROOT, check=True)
                total = validate(folder, code, region, kinds)
                if total:
                    artisan_import(folder, dry_run=True)
                    counts = artisan_import(folder)
                else:
                    counts = dict(created=0, updated=0, unchanged=0, failed=0)
                state['completed'][key] = {'exported': total, **counts}
                state['failed'].pop(key, None)
                print(f'LIVE {key}: {total} routes', flush=True)
            except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as exc:
                state['failed'][key] = str(exc)
                print(f'FAILED {key}: {exc}', flush=True)
            save(status_path, state)
    state['status'] = 'complete' if len(state['completed']) == len(jobs) else 'failed'
    state.pop('current', None)
    save(status_path, state)
    print(json.dumps(state, ensure_ascii=False), flush=True)
    return 0 if state['status'] == 'complete' else 1


if __name__ == '__main__':
    sys.exit(main())
