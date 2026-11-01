#!/usr/bin/env python3
"""Resumable worldwide hiking/foot import. ZZ means country not yet identified."""
import fcntl
import importlib.util
import json
import os
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
osm_spec = importlib.util.spec_from_file_location('osm_world', next(p for p in [ROOT / 'milmap_routes.py', ROOT / 'milmap_routes_europe.py'] if p.exists()))
osm = importlib.util.module_from_spec(osm_spec)
osm_spec.loader.exec_module(osm)

spec = importlib.util.spec_from_file_location('runner', ROOT / 'scripts/import-europe-routes.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def lines_for(relation, relations, ancestors=frozenset()):
    """Keep member segments separate, including nested routes, without inventing links."""
    if relation['id'] in ancestors:
        return []
    ancestors = ancestors | {relation['id']}
    lines = []
    for member in relation.get('members', []):
        if member['type'] == 'way':
            points = [(p['lat'], p['lon']) for p in member.get('geometry', []) if p]
            if len(points) >= 2:
                lines.append(points)
        elif member['type'] == 'relation' and member['ref'] in relations:
            lines.extend(lines_for(relations[member['ref']], relations, ancestors))
    return lines


def main():
    base = ROOT / 'storage/app/imports/world-walking-20261101'
    base.mkdir(parents=True, exist_ok=True)
    lock = (base / 'runner.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    status_path = base / 'status.json'
    state = json.loads(status_path.read_text()) if status_path.exists() else {'completed': {}, 'unusable': {}}
    state['status'] = 'waiting_for_existing_imports'
    runner.save(status_path, state)
    while runner.belgium_running() or runner.other_europe_runner_running():
        time.sleep(30)
    state['status'] = 'inventory'
    runner.save(status_path, state)
    inventory = base / 'inventory.json'
    if not inventory.exists():
        result = osm.overpass('[out:json][timeout:240];relation["type"="route"]["route"~"^(hiking|foot)$"];out ids;')
        ids = sorted({e['id'] for e in result['elements'] if e['type'] == 'relation'})
        if not ids:
            raise RuntimeError('Empty global inventory')
        runner.save(inventory, ids)
    ids = json.loads(inventory.read_text())
    state['total_candidates'] = len(ids)
    # Preserve known country/province values of existing records when updating.
    code = 'echo json_encode(\\App\\Models\\TrailRoute::where("source","osm")->get(["source_id","country","province"])->keyBy("source_id"));'
    existing = subprocess.check_output(['php', 'artisan', 'tinker', '--execute=' + code], cwd=ROOT, text=True)
    locations = json.loads(existing)
    # Build a resumable country index. Border routes retain their existing country.
    countries_path = base / 'countries.json'
    if not countries_path.exists():
        result = osm.overpass('[out:json][timeout:240];relation["boundary"="administrative"]["admin_level"="2"]["ISO3166-1"];out tags;')
        runner.save(countries_path, sorted({e['tags']['ISO3166-1'] for e in result['elements'] if len(e.get('tags', {}).get('ISO3166-1', '')) == 2}))
    for country in json.loads(countries_path.read_text()):
        path = base / ('country-' + country + '.json')
        if not path.exists():
            result = osm.overpass('[out:json][timeout:240];area["boundary"="administrative"]["admin_level"="2"]["ISO3166-1"="' + country + '"]->.a;relation(area.a)["type"="route"]["route"~"^(hiking|foot)$"];out ids;.a out ids;')
            if not any(e['type'] == 'area' for e in result['elements']):
                print('Country area unavailable: ' + country, flush=True)
                continue
            runner.save(path, [e['id'] for e in result['elements'] if e['type'] == 'relation'])
        for relation_id in json.loads(path.read_text()):
            locations.setdefault(str(relation_id), {'country': country, 'province': None})
        state.update(status='indexing_countries', current=country)
        runner.save(status_path, state)
        time.sleep(2)
    for offset in range(0, len(ids), 30):
        key = str(offset)
        if key in state['completed']:
            continue
        batch = ids[offset:offset + 30]
        state.update(status='running', current=offset)
        runner.save(status_path, state)
        query = '[out:json][timeout:240];relation(id:' + ','.join(map(str, batch)) + ');(._; >>;);out body geom;'
        result = osm.overpass(query)
        relations = {e['id']: e for e in result['elements'] if e['type'] == 'relation'}
        folder = base / ('batch-' + key)
        folder.mkdir(exist_ok=True)
        routes = []
        unusable = {}
        for relation_id in batch:
            rel = relations.get(relation_id)
            segments = lines_for(rel, relations) if rel else []
            if not segments:
                unusable[str(relation_id)] = 'missing relation or no usable path geometry'
                continue
            tags = rel.get('tags', {})
            name = tags.get('name') or tags.get('ref') or ('OSM wandelroute ' + str(relation_id))
            slug = 'osm-wandelen-' + str(relation_id)
            (folder / (slug + '.gpx')).write_text(osm.to_gpx(name, relation_id, 'wandelen', segments, tags))
            location = locations.get(str(relation_id), {'country': 'ZZ', 'province': None})
            routes.append(dict(osm_relation=relation_id, name=name, slug=slug, type='wandelen',
                country=location['country'], province=location['province'],
                distance_km=round(osm.length_km(segments), 1), is_loop=osm.haversine(segments[0][0], segments[-1][-1]) < 30,
                network=tags.get('network'), operator=tags.get('operator'), website=tags.get('website'),
                gpx_file=slug + '.gpx'))
        runner.save(folder / 'routes.json', {'routes': routes})
        runner.save(folder / 'osm-tags.json', {str(i): relations[i].get('tags', {}) for i in batch if i in relations})
        counts = dict(created=0, updated=0, unchanged=0, failed=0)
        if routes:
            runner.artisan_import(folder, dry_run=True)
            counts = runner.artisan_import(folder)
        state['unusable'].update(unusable)
        state['completed'][key] = counts
        runner.save(status_path, state)
        print(json.dumps(dict(offset=offset, total=len(ids), **counts)), flush=True)
        time.sleep(2)
    state.update(status='complete_with_unusable' if state['unusable'] else 'complete')
    state.pop('current', None)
    runner.save(status_path, state)


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('FAILED: ' + str(exc), file=sys.stderr, flush=True)
        raise
