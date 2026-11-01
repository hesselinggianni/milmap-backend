#!/usr/bin/env python3
"""Resumable inclusive European OSM MTB/bicycle import. See osm-country-imports.md."""
import importlib.util
import json
import math
import os
import subprocess
import sys
import time
import fcntl
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('walking', ROOT / 'scripts/import-world-walking.py')
walking = importlib.util.module_from_spec(spec)
spec.loader.exec_module(walking)
osm, runner = walking.osm, walking.runner
COUNTRIES = 'AL AD AT BY BE BA BG HR CY CZ DK EE FI FR DE GR VA HU IS IE IT XK LV LI LT LU MT MD MC ME NL MK NO PL PT RO RU SM RS SK SI ES SE CH TR UA GB GE AM AZ KZ FO GI AX GG JE IM SJ'.split()
# Inclusive operational Europe envelope, applied to relation membership queries.
BBOX = '34,-32,82,60'
BASE = ROOT / 'storage/app/imports/europe-cycling-20261101'


def predecessors_running():
    output = subprocess.check_output(['ps', '-eo', 'pid=,args='], text=True)
    names = {'import-europe-routes.py', 'import-world-walking.py', 'milmap_routes_be.py', 'milmap_routes_europe.py', 'milmap_routes.py', 'milmap-belgium-resume-20261031.sh', 'milmap-belgium-finish-20261031.sh'}
    for line in output.splitlines():
        parts = line.split()
        if len(parts) > 2 and int(parts[0]) != os.getpid() and Path(parts[1]).name in {'python3', 'python', 'bash', 'php'}:
            args = parts[2:]
            if args and args[0] == '-c':
                continue
            script = next((p for p in args if not p.startswith('-')), '')
            if Path(script).name in names or (Path(script).name == 'artisan' and 'milmap:import-routes' in args):
                return True
    return False


def segments_for(rel, relations):
    lines = walking.lines_for(rel, relations)
    valid = []
    for line in lines:
        if all(math.isfinite(lat) and math.isfinite(lon) and -90 <= lat <= 90 and -180 <= lon <= 180 for lat, lon in line):
            if any(p != line[0] for p in line[1:]):
                valid.append(line)
    return valid


def main():
    BASE.mkdir(parents=True, exist_ok=True)
    lock = (BASE / 'runner.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    path = BASE / 'status.json'
    state = json.loads(path.read_text()) if path.exists() else {'completed': {}, 'failed': {}, 'unusable': {}}
    def save(**fields):
        state.update(fields, updated_at=time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), pid=os.getpid())
        runner.save(path, state)
    save(status='waiting_for_existing_imports', countries=COUNTRIES, bbox=BBOX, types=['mtb', 'bicycle'])
    while predecessors_running():
        time.sleep(30)
    # Serial import guard for multiple instances of this task (also held while waiting).
    inventory = {}
    for country in COUNTRIES:
        cache = BASE / ('country-' + country + '.json')
        save(status='inventory', current=country)
        if not cache.exists():
            try:
                result = osm.overpass('[out:json][timeout:240];area["boundary"="administrative"]["ISO3166-1"="' + country + '"]->.a;relation(area.a)(' + BBOX + ')["type"="route"]["route"~"^(mtb|bicycle)$"];out ids;.a out ids;')
                if not any(e['type'] == 'area' for e in result['elements']):
                    raise RuntimeError('Country area unavailable')
                runner.save(cache, sorted({e['id'] for e in result['elements'] if e['type'] == 'relation'}))
                state['failed'].pop('country/' + country, None)
            except Exception as exc:
                state['failed']['country/' + country] = str(exc)
                save()
                continue
        for rid in json.loads(cache.read_text()):
            inventory.setdefault(rid, country)
        time.sleep(2)
    code = 'echo json_encode(\\App\\Models\\TrailRoute::where("source","osm")->get(["source_id","country","province"])->keyBy("source_id"));'
    locations = json.loads(subprocess.check_output(['php', 'artisan', 'tinker', '--execute=' + code], cwd=ROOT, text=True))
    ids = sorted(inventory)
    runner.save(BASE / 'inventory.json', [{'id': rid, 'country': inventory[rid]} for rid in ids])
    save(total_candidates=len(ids))
    # Batch keys use relation IDs, so a newly successful country cannot shift resume offsets.
    for offset in range(0, len(ids), 30):
        batch = ids[offset:offset + 30]
        key = '-'.join(map(str, batch))
        if key in state['completed']:
            continue
        folder = BASE / ('batch-' + str(batch[0]))
        folder.mkdir(exist_ok=True)
        save(status='running', current=batch)
        try:
            result = osm.overpass('[out:json][timeout:240];relation(id:' + ','.join(map(str, batch)) + ');(._; >>;);out body geom;')
            runner.save(folder / 'osm-response.json', result)
            relations = {e['id']: e for e in result['elements'] if e['type'] == 'relation'}
            routes, unusable = [], {}
            for rid in batch:
                rel = relations.get(rid)
                segments = segments_for(rel, relations) if rel else []
                if not segments:
                    unusable[str(rid)] = 'missing relation or no finite, nonzero path geometry'
                    continue
                tags = rel.get('tags', {})
                if tags.get('route') not in {'mtb', 'bicycle'}:
                    unusable[str(rid)] = 'relation no longer has route=mtb/bicycle'
                    continue
                kind = 'mtb' if tags['route'] == 'mtb' else 'fietsen'
                name = tags.get('name') or tags.get('ref') or ('OSM ' + kind + ' ' + str(rid))
                slug = 'osm-' + kind + '-' + str(rid)
                (folder / (slug + '.gpx')).write_text(osm.to_gpx(name, rid, kind, segments, tags))
                routes.append(dict(osm_relation=rid, name=name, slug=slug, type=kind, country=locations.get(str(rid), {}).get('country') or inventory[rid], province=locations.get(str(rid), {}).get('province'),
                    distance_km=round(osm.length_km(segments), 1), is_loop=osm.haversine(segments[0][0], segments[-1][-1]) < 30,
                    network=tags.get('network'), operator=tags.get('operator'), website=tags.get('website'), gpx_file=slug + '.gpx'))
            runner.save(folder / 'routes.json', {'routes': routes})
            runner.save(folder / 'unusable.json', unusable)
            counts = dict(created=0, updated=0, unchanged=0, failed=0)
            if routes:
                runner.artisan_import(folder, dry_run=True)
                counts = runner.artisan_import(folder)
            state['unusable'].update(unusable)
            state['completed'][key] = dict(candidates=len(batch), exported=len(routes), **counts)
            state['failed'].pop(key, None)
        except Exception as exc:
            state['failed'][key] = str(exc)
            runner.save(folder / 'failure.json', {'error': str(exc), 'ids': batch})
        save()
        time.sleep(2)
    save(status='failed' if state['failed'] else ('complete_with_unusable' if state['unusable'] else 'complete'), current=None)


if __name__ == '__main__':
    main()
