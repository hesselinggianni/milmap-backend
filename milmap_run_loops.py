#!/usr/bin/env python3
"""
Genereert hardlooprondjes (standaard 5 en 10 km) in en rond Nederlandse parken en
bossen en schrijft ze als GPX + een manifest (routes.json) voor `milmap:import-routes`.

OpenStreetMap kent maar enkele tientallen echte hardlooproutes (zie milmap_routes.py).
Dit script vult dat aan met rondjes die over bestaande paden lopen:

  1. per provincie de grootste plaatsen (Overpass, place=city|town, op inwoners);
  2. per plaats de grootste benoemde parken/bossen/natuurgebieden binnen 5 km;
  3. per gebied en lengte een rondje: een driehoek start -> A -> B -> start, verbonden
     door Valhalla (zelfde hardloopprofiel als de routeplanner). Eén proefrondje meet
     hoe kronkelig de paden zijn, daarna volgt een straal die bij de lengte past
     (zelfde werkwijze als MilMap-Frontend/src/utils/loopRoutes.js);
  4. rondjes die te ver van de gewenste lengte zitten, te veel heen-en-terug gaan,
     het gebied verlaten of vooral langs straten lopen vallen af; van de rest wint
     de beste.

Het resultaat is dus géén in OSM gemapte route: het manifest markeert elke route met
source=generated (anders dan milmap_routes.py) en de bronvermelding noemt MilMap.

Gebruik:
    python3 milmap_run_loops.py --out storage/app/imports/osm-hardlopen-gegenereerd
    python3 milmap_run_loops.py --regions drenthe --steden-per-provincie 1 --lijst
    python3 milmap_run_loops.py --km 5 10 15 --parken-per-stad 3

Hervatbaar: elk gebied+lengte wordt in <out>/.cache bewaard, een tweede run slaat dat over.
Alleen de Python-standaardbibliotheek. Eigen Valhalla: env MILMAP_VALHALLA=https://...
Data: (c) OpenStreetMap contributors, ODbL 1.0 — bronvermelding verplicht.
"""

import argparse
import json
import math
import os
import re
import shutil
import ssl
import subprocess
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from xml.sax.saxutils import escape

OVERPASS = [
    "https://overpass-api.de/api/interpreter",
    "https://lz4.overpass-api.de/api/interpreter",
]
VALHALLA = os.environ.get("MILMAP_VALHALLA", "https://valhalla1.openstreetmap.de").rstrip("/")
USER_AGENT = "MilmapRouteGenerator/1.0 (+https://milmap.nl)"
# Publieke Valhalla is gratis: niet harder dan dit aanspreken.
VALHALLA_PAUSE_S = 0.7

PROVINCES = {
    "groningen": "NL-GR", "friesland": "NL-FR", "drenthe": "NL-DR",
    "overijssel": "NL-OV", "flevoland": "NL-FL", "gelderland": "NL-GE",
    "utrecht": "NL-UT", "noord-holland": "NL-NH", "zuid-holland": "NL-ZH",
    "zeeland": "NL-ZE", "noord-brabant": "NL-NB", "limburg": "NL-LI",
}
ATTRIBUTION = "Route gegenereerd door MilMap op basis van © OpenStreetMap contributors, ODbL 1.0"
KIND = "hardlopen"

# Hetzelfde profiel als "run" in MilMap-Frontend/src/services/RoutingService.js.
RUN_OPTIONS = {"walking_speed": 10, "walkway_factor": 0.7, "use_tracks": 1, "use_living_streets": 0.35, "step_penalty": 30}

LOOP_ANGLE = 80
DEFAULT_WINDING = 1.35
MAX_LENGTH_ERROR = 0.12      # rondje mag hooguit 12% van de titel afwijken
MAX_OVERLAP = 0.2            # aandeel dat twee keer gelopen wordt
MIN_QUIET_SHARE = 0.45       # aandeel pad/track/voetpad/fietspad (geen straat of trottoir)
MIN_GREEN_SHARE = 0.5        # aandeel van het rondje in/bij het gebied waar de naam naar verwijst
MAX_SNAP_M = 300             # het gebiedscentrum moet zo dicht bij een pad liggen

# Valhalla edge.use: wat telt als "rustig" om op te hardlopen.
QUIET_USES = {"footway", "path", "pedestrian", "track", "cycleway", "mountain_bike", "bridleway"}


def log(message, error=False):
    print(message, file=sys.stderr if error else sys.stdout, flush=True)


# ── Netwerk ───────────────────────────────────────────────────────────────

def http_post(url, body, content_type):
    """(status, tekst). Oude Python/LibreSSL (macOS) kan geen TLS naar sommige hosts: dan curl."""
    try:
        req = urllib.request.Request(url, data=body, headers={"User-Agent": USER_AGENT, "Content-Type": content_type})
        with urllib.request.urlopen(req, timeout=300) as resp:
            return resp.status, resp.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", "replace")
    except urllib.error.URLError as exc:
        if not (isinstance(exc.reason, ssl.SSLError) and shutil.which("curl")):
            raise
        out = subprocess.run(
            ["curl", "-sS", "-m", "300", "-A", USER_AGENT, "-H", f"Content-Type: {content_type}",
             "--data-binary", "@-", "-w", "\n%{http_code}", url],
            input=body, capture_output=True, check=True,
        ).stdout.decode("utf-8", "replace")
        text, _, code = out.rpartition("\n")
        return int(code), text


def overpass(query, retries=3):
    body = ("data=" + urllib.parse.quote(query, safe="")).encode()
    last = None
    for attempt in range(retries):
        for url in OVERPASS:
            try:
                status, text = http_post(url, body, "application/x-www-form-urlencoded")
                if status == 200:
                    return json.loads(text)
                last = f"HTTP {status}"
            except Exception as exc:  # netwerk, timeout, ongeldige JSON
                last = exc
            log(f"  ! {url} faalde ({last}), volgende proberen...", error=True)
        time.sleep(10 * (attempt + 1))
    raise RuntimeError(f"Overpass onbereikbaar: {last}")


_last_valhalla = 0.0


def valhalla(path, payload, retries=4):
    """Valhalla-aanvraag. (status, json): 400 is een inhoudelijk antwoord ('geen pad'), de rest wordt herhaald."""
    global _last_valhalla
    body = json.dumps(payload).encode()
    last = None
    for attempt in range(retries):
        wait = VALHALLA_PAUSE_S - (time.time() - _last_valhalla)
        if wait > 0:
            time.sleep(wait)
        try:
            status, text = http_post(f"{VALHALLA}{path}", body, "application/json")
        except Exception as exc:
            status, text, last = 0, "", exc
        _last_valhalla = time.time()
        if status in (200, 400):
            try:
                return status, json.loads(text)
            except ValueError:
                last = f"ongeldig antwoord (HTTP {status})"
        else:
            last = last or f"HTTP {status}"
        time.sleep(5 * (attempt + 1))
    raise RuntimeError(f"Valhalla onbereikbaar: {last}")


# ── Meetkunde ─────────────────────────────────────────────────────────────

RAD = math.pi / 180


def haversine(a, b):
    """Afstand in meter tussen twee [lon, lat]-punten."""
    lat1, lon1, lat2, lon2 = a[1] * RAD, a[0] * RAD, b[1] * RAD, b[0] * RAD
    h = math.sin((lat2 - lat1) / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin((lon2 - lon1) / 2) ** 2
    return 2 * 6371000 * math.asin(math.sqrt(h))


def path_length(coords):
    return sum(haversine(coords[i], coords[i + 1]) for i in range(len(coords) - 1))


def destination(origin, bearing_deg, dist_m):
    d = dist_m / 6371000
    b = bearing_deg * RAD
    lat1, lon1 = origin[1] * RAD, origin[0] * RAD
    lat2 = math.asin(math.sin(lat1) * math.cos(d) + math.cos(lat1) * math.sin(d) * math.cos(b))
    lon2 = lon1 + math.atan2(math.sin(b) * math.sin(d) * math.cos(lat1), math.cos(d) - math.sin(lat1) * math.sin(lat2))
    return [((lon2 / RAD + 540) % 360) - 180, lat2 / RAD]


def perimeter_factor():
    return 2 + 2 * math.sin(LOOP_ANGLE * RAD / 2)


def decode_polyline6(s):
    coords, i, lat, lon = [], 0, 0, 0
    while i < len(s):
        for axis in (0, 1):
            shift = result = 0
            while True:
                b = ord(s[i]) - 63
                i += 1
                result |= (b & 0x1F) << shift
                shift += 5
                if b < 0x20:
                    break
            delta = ~(result >> 1) if result & 1 else result >> 1
            if axis == 0:
                lat += delta
            else:
                lon += delta
        coords.append([lon / 1e6, lat / 1e6])
    return coords


def encode_polyline6(coords):
    out, plat, plon = [], 0, 0
    for lon, lat in coords:
        la, lo = round(lat * 1e6), round(lon * 1e6)
        for v in (la - plat, lo - plon):
            v = ~(v << 1) if v < 0 else v << 1
            while v >= 0x20:
                out.append(chr((0x20 | (v & 0x1F)) + 63))
                v >>= 5
            out.append(chr(v + 63))
        plat, plon = la, lo
    return "".join(out)


def resample(coords, step_m=20):
    """Punten om de `step_m` meter langs een lijn; eerste en laatste punt altijd mee."""
    if not coords:
        return []
    out, since = [coords[0]], 0.0
    for i in range(1, len(coords)):
        a, b = coords[i - 1], coords[i]
        seg = haversine(a, b)
        while seg > 0 and since + seg >= step_m:
            t = (step_m - since) / seg
            a = [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t]
            out.append(a)
            seg = haversine(a, b)
            since = 0.0
        since += seg
    if haversine(out[-1], coords[-1]) > 1:
        out.append(coords[-1])
    return out


def loop_overlap(legs, step_m=20, cell_m=25, knot_m=150):
    """Aandeel (0..1) van het rondje dat over een pad loopt dat twee keer voorkomt (heen-en-terug)."""
    legs = [l for l in legs if len(l) > 1]
    if not legs:
        return 0.0
    lat0 = legs[0][0][1]
    dlat = cell_m / 111320
    dlon = dlat / max(0.05, math.cos(lat0 * RAD))
    cell = lambda p: (math.floor(p[1] / dlat), math.floor(p[0] / dlon))
    near = lambda seen, c: any((c[0] + y, c[1] + x) in seen for y in (-1, 0, 1) for x in (-1, 0, 1))
    knots = [legs[0][0]] + [l[-1] for l in legs]
    seen, total, double = set(), 0, 0
    for leg in legs:
        own = []
        for p in resample(leg, step_m):
            c = cell(p)
            own.append(c)
            if any(haversine(k, p) < knot_m for k in knots):
                continue
            total += 1
            if near(seen, c):
                double += 1
        seen.update(own)
    return min(1.0, 2 * double / total) if total else 0.0


# ── Rondjes bouwen ────────────────────────────────────────────────────────

def snap(point):
    """Dichtstbijzijnde punt op een pad, of None."""
    status, data = valhalla("/locate", {"locations": [{"lon": point[0], "lat": point[1]}], "costing": "pedestrian", "verbose": False})
    edges = (data[0].get("edges") if status == 200 and data else None) or []
    best = None
    for e in edges:
        p = [e["correlated_lon"], e["correlated_lat"]]
        d = haversine(point, p)
        if best is None or d < best[1]:
            best = (p, d)
    return best


def build_loop(start, bearing, radius_m):
    """Eén rondje start -> A -> B -> start. None als Valhalla geen pad vindt."""
    a = destination(start, bearing, radius_m)
    b = destination(start, bearing + LOOP_ANGLE, radius_m)
    points = [start, a, b, start]
    status, data = valhalla("/route", {
        "locations": [{"lon": p[0], "lat": p[1], "type": "break"} for p in points],
        "costing": "pedestrian",
        "costing_options": {"pedestrian": RUN_OPTIONS},
        "directions_type": "none",
    })
    if status == 400:
        return None
    legs = [decode_polyline6(l["shape"]) for l in data["trip"]["legs"]]
    if len(legs) != 3 or any(len(l) < 2 for l in legs):
        return None
    coords = legs[0] + legs[1][1:] + legs[2][1:]
    return {"legs": legs, "coords": coords, "distance_m": path_length(coords)}


def clamp(v, lo, hi):
    return min(hi, max(lo, v))


def candidate_loops(start, target_m, directions):
    """Rondjes in `directions` richtingen, elk bijgesteld naar de gewenste lengte."""
    err = lambda l: abs(l["distance_m"] - target_m) / target_m
    first_bearing = 17
    bearings = [(first_bearing + i * 360 / directions) % 360 for i in range(directions)]

    r0 = target_m / (DEFAULT_WINDING * perimeter_factor())
    proef = build_loop(start, bearings[0], r0)
    winding = clamp(proef["distance_m"] / (r0 * perimeter_factor()), 1.05, 2.5) if proef else DEFAULT_WINDING
    radius = target_m / (winding * perimeter_factor())

    loops = []
    for i, bearing in enumerate(bearings):
        loop = proef if (i == 0 and proef and err(proef) <= MAX_LENGTH_ERROR) else build_loop(start, bearing, radius)
        if loop is None:  # water of geen pad: één poging met een kleiner rondje
            loop = build_loop(start, bearing, radius * 0.6)
        if loop and err(loop) > MAX_LENGTH_ERROR * 0.8:
            better = build_loop(start, bearing, clamp(radius * target_m / loop["distance_m"], radius * 0.5, radius * 2))
            if better and err(better) < err(loop):
                loop = better
        if loop:
            loop["overlap"] = loop_overlap(loop["legs"])
            loop["error"] = err(loop)
            loops.append(loop)
    return loops


def quiet_share(coords):
    """Aandeel van het rondje over pad/track/voetpad/fietspad; None als Valhalla niets teruggeeft."""
    polyline = encode_polyline6(coords)
    for match in ("edge_walk", "map_snap"):
        status, data = valhalla("/trace_attributes", {
            "encoded_polyline": polyline, "costing": "pedestrian", "shape_match": match,
            "filters": {"attributes": ["edge.length", "edge.use"], "action": "include"},
        })
        if status == 200 and data.get("edges"):
            total = sum(e.get("length", 0) for e in data["edges"])
            quiet = sum(e.get("length", 0) for e in data["edges"] if e.get("use") in QUIET_USES)
            return quiet / total if total > 0 else None
    return None


def green_share(coords, bbox):
    """Aandeel van het rondje binnen het gebied (bbox, ruim genomen); [minlon, minlat, maxlon, maxlat]."""
    w, h = bbox[2] - bbox[0], bbox[3] - bbox[1]
    dx, dy = max(0.25 * w, 0.0045), max(0.25 * h, 0.003)
    pts = resample(coords, 50)
    inside = sum(1 for p in pts if bbox[0] - dx <= p[0] <= bbox[2] + dx and bbox[1] - dy <= p[1] <= bbox[3] + dy)
    return inside / len(pts) if pts else 0.0


def best_loop(start, target_m, bbox, directions):
    loops = [l for l in candidate_loops(start, target_m, directions)
             if l["error"] <= MAX_LENGTH_ERROR and l["overlap"] <= MAX_OVERLAP]
    for l in loops:
        l["green"] = green_share(l["coords"], bbox)
    loops = [l for l in loops if l["green"] >= MIN_GREEN_SHARE]
    loops.sort(key=lambda l: l["error"] + 1.5 * l["overlap"])
    scored = []
    for l in loops[:2]:  # trace_attributes alleen voor de beste twee: scheelt aanvragen
        q = quiet_share(l["coords"])
        if q is not None and q >= MIN_QUIET_SHARE:
            l["quiet"] = q
            scored.append((l["error"] + 1.5 * l["overlap"] + 0.8 * (1 - q), l))
    return min(scored, key=lambda s: s[0])[1] if scored else None


# ── Plekken kiezen ────────────────────────────────────────────────────────

def digits(value):
    return int(re.sub(r"\D", "", value or "") or 0)


def fetch_towns(iso):
    query = f"""[out:json][timeout:120];
    area["ISO3166-2"="{iso}"]["admin_level"="4"]->.a;
    node["place"~"^(city|town)$"]["name"](area.a);
    out;"""
    towns = [{"id": e["id"], "name": e["tags"]["name"], "lon": e["lon"], "lat": e["lat"], "population": digits(e["tags"].get("population"))}
             for e in overpass(query).get("elements", [])]
    return sorted(towns, key=lambda t: -t["population"])


def pick_towns(towns, count, min_gap_km=6):
    """De grootste plaatsen, maar geen buurplaatsen van een eerder gekozen plaats (Almere Stad/Haven)."""
    picked = []
    for t in towns:
        if all(haversine([t["lon"], t["lat"]], [p["lon"], p["lat"]]) > min_gap_km * 1000 for p in picked):
            picked.append(t)
        if len(picked) >= count:
            break
    return picked


def fetch_spaces(town, radius_m=5000):
    selectors = [
        '["leisure"="park"]', '["landuse"="forest"]', '["natural"="wood"]', '["leisure"="nature_reserve"]',
    ]
    body = "".join(f'way{s}["name"](around.t:{radius_m});relation{s}["name"](around.t:{radius_m});' for s in selectors)
    query = f"[out:json][timeout:90];node(id:{town['id']})->.t;({body});out tags bb;"
    spaces = []
    for e in overpass(query).get("elements", []):
        b = e.get("bounds")
        if not b or not e.get("tags", {}).get("name"):
            continue
        bbox = [b["minlon"], b["minlat"], b["maxlon"], b["maxlat"]]
        w = haversine([bbox[0], bbox[1]], [bbox[2], bbox[1]]) / 1000
        h = haversine([bbox[0], bbox[1]], [bbox[0], bbox[3]]) / 1000
        spaces.append({
            "name": e["tags"]["name"], "osm_type": e["type"], "osm_id": e["id"], "bbox": bbox,
            "area_km2": round(w * h, 2), "diagonal_km": round(math.hypot(w, h), 2),
            "center": [(bbox[0] + bbox[2]) / 2, (bbox[1] + bbox[3]) / 2],
        })
    return spaces


def pick_spaces(spaces, count, min_area_km2=0.2, min_gap_km=1.5):
    picked = []
    for s in sorted(spaces, key=lambda s: -s["area_km2"]):
        if s["area_km2"] < min_area_km2:
            break
        if any(s["osm_id"] == p["osm_id"] for p in picked):
            continue
        if all(haversine(s["center"], p["center"]) > min_gap_km * 1000 for p in picked):
            picked.append(s)
        if len(picked) >= count:
            break
    return picked


# ── Uitvoer ───────────────────────────────────────────────────────────────

def slugify(text):
    text = unicodedata.normalize("NFKD", text.replace("ß", "ss")).encode("ascii", "ignore").decode("ascii")
    text = re.sub(r"[^\w\s-]", "", text.lower())
    return re.sub(r"[\s_-]+", "-", text).strip("-")[:80] or "route"


def to_gpx(name, coords):
    lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<gpx version="1.1" creator="Milmap" xmlns="http://www.topografix.com/GPX/1/1">',
        "  <metadata>",
        f"    <name>{escape(name)}</name>",
        '    <copyright author="OpenStreetMap contributors"><license>https://opendatacommons.org/licenses/odbl/1-0/</license></copyright>',
        "  </metadata>",
        "  <trk>",
        f"    <name>{escape(name)}</name>",
        "    <desc>Door MilMap gegenereerd hardlooprondje over bestaande paden (OpenStreetMap).</desc>",
        f"    <type>{KIND}</type>",
        "    <trkseg>",
    ]
    lines.extend(f'      <trkpt lat="{lat:.6f}" lon="{lon:.6f}"/>' for lon, lat in coords)
    lines += ["    </trkseg>", "  </trk>", "</gpx>", ""]
    return "\n".join(lines)


def route_name(km, space, town):
    park = space["name"]
    return f"{km:g} km hardlooprondje {park}" + ("" if town.lower() in park.lower() else f", {town}")


def source_id(space, km):
    """Stabiel nummer per gebied+lengte: way/relation-id × 100 + km, relaties met een offset zodat ze nooit botsen."""
    return (10**12 if space["osm_type"] == "relation" else 0) + space["osm_id"] * 100 + int(km)


def read_json(path, default=None):
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else default


def write_json(path, data, **kw):
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, **kw), encoding="utf-8")
    tmp.replace(path)


def write_manifest(out_dir):
    routes = []
    for path in sorted((out_dir / ".cache").glob("loop-*.json")):
        data = read_json(path)
        if data and data.get("route"):
            routes.append(data["route"])
    write_json(out_dir / "routes.json", {
        "generated": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "attribution": ATTRIBUTION,
        "license": "https://opendatacommons.org/licenses/odbl/1-0/",
        "routes": routes,
    }, indent=2)
    return len(routes)


def generate(out_dir, province, town, space, km, directions, used_slugs):
    key = f"loop-{space['osm_type']}{space['osm_id']}-{km:g}"
    cache = out_dir / ".cache" / f"{key}.json"
    if cache.exists():
        return "cache"

    label = f"{town} / {space['name']} / {km:g} km"
    start = snap(space["center"])
    if not start or start[1] > MAX_SNAP_M:
        write_json(cache, {"status": "geen pad bij het gebied"})
        log(f"  - {label}: geen pad binnen {MAX_SNAP_M} m van het gebied")
        return "geen"

    loop = best_loop(start[0], km * 1000, space["bbox"], directions)
    if not loop:
        write_json(cache, {"status": "geen goed rondje"})
        log(f"  - {label}: geen rondje dat aan de eisen voldoet")
        return "geen"

    name = route_name(km, space, town)
    slug = slugify(name)
    if slug in used_slugs:
        slug = f"{slug}-{space['osm_id']}"
    used_slugs.add(slug)

    coords = loop["coords"]
    lons, lats = [c[0] for c in coords], [c[1] for c in coords]
    gpx = Path(KIND) / province / f"{slug}.gpx"
    (out_dir / gpx).parent.mkdir(parents=True, exist_ok=True)
    (out_dir / gpx).write_text(to_gpx(name, coords), encoding="utf-8")
    s = coords[0]
    route = {
        "slug": slug, "name": name, "type": KIND, "country": "NL", "province": province,
        "distance_km": round(loop["distance_m"] / 1000, 1), "segments": 1, "is_loop": True,
        "start": {"lat": s[1], "lon": s[0]},
        "bbox": [min(lons), min(lats), max(lons), max(lats)],
        "network": None, "operator": None, "website": None,
        "gpx_file": str(gpx),
        "source": "generated", "source_id": source_id(space, km),
        "source_url": f"https://www.openstreetmap.org/?mlat={s[1]:.5f}&mlon={s[0]:.5f}#map=15/{s[1]:.5f}/{s[0]:.5f}",
        "attribution": ATTRIBUTION,
        # Informatief (de importer negeert dit): waarom dit rondje gekozen is.
        "generated": {
            "area": f"{space['osm_type']}/{space['osm_id']}", "town": town,
            "overlap": round(loop["overlap"], 3), "quiet_share": round(loop["quiet"], 2), "green_share": round(loop["green"], 2),
        },
    }
    write_json(cache, {"status": "ok", "route": route})
    log(f"  ✓ {name} ({route['distance_km']} km, rustig {loop['quiet']:.0%}, overlap {loop['overlap']:.0%})")
    return "ok"


def selection(out_dir, province, iso, towns_n, spaces_n):
    """Plaatsen en gebieden per provincie; bewaard zodat een tweede run dezelfde kiest."""
    cache = out_dir / ".cache" / f"seeds-{province}-{towns_n}-{spaces_n}.json"
    picked = read_json(cache)
    if picked is not None:
        return picked
    picked = []
    for town in pick_towns(fetch_towns(iso), towns_n):
        spaces = pick_spaces(fetch_spaces(town), spaces_n)
        picked.append({"town": town, "spaces": spaces})
        time.sleep(2)
    write_json(cache, picked, indent=1)
    return picked


def main():
    parser = argparse.ArgumentParser(description="Hardlooprondjes genereren voor Milmap")
    parser.add_argument("--out", default="milmap_loops")
    parser.add_argument("--km", type=float, nargs="+", default=[5, 10], help="lengtes van de rondjes")
    parser.add_argument("--steden-per-provincie", type=int, default=3)
    parser.add_argument("--parken-per-stad", type=int, default=2)
    parser.add_argument("--richtingen", type=int, default=8, help="aantal richtingen per rondje (meer = betere keuze, meer aanvragen)")
    parser.add_argument("--regions", help="alleen deze provincies, kommagescheiden")
    parser.add_argument("--lijst", action="store_true", help="alleen tonen welke plaatsen/gebieden gekozen worden")
    args = parser.parse_args()

    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)
    only = set(args.regions.split(",")) if args.regions else None
    used_slugs = set()
    for path in (out_dir / ".cache").glob("loop-*.json"):
        data = read_json(path)
        if data and data.get("route"):
            used_slugs.add(data["route"]["slug"])

    failures, stats = 0, {"ok": 0, "geen": 0, "cache": 0}
    for province, iso in PROVINCES.items():
        if only and province not in only:
            continue
        log(f"\n[{province}]")
        try:
            picked = selection(out_dir, province, iso, args.steden_per_provincie, args.parken_per_stad)
        except RuntimeError as exc:
            log(f"[{province}] overgeslagen: {exc}", error=True)
            failures += 1
            continue
        for item in picked:
            town = item["town"]["name"]
            for space in item["spaces"]:
                if args.lijst:
                    log(f"  {town}: {space['name']} ({space['osm_type']}/{space['osm_id']}, {space['area_km2']} km²)")
                    continue
                for km in args.km:
                    # Een rondje van 10 km past niet in een parkje: de gebiedscontrole zou het toch afkeuren.
                    if space["diagonal_km"] < km * 0.2:
                        continue
                    try:
                        stats[generate(out_dir, province, town, space, km, args.richtingen, used_slugs)] += 1
                    except RuntimeError as exc:
                        failures += 1
                        log(f"  ! {town}/{space['name']}/{km:g} km overgeslagen: {exc}", error=True)
                        if failures >= 3:
                            write_manifest(out_dir)
                            log("Valhalla/Overpass lijkt onbereikbaar: gestopt. Draai hetzelfde commando later opnieuw.", error=True)
                            sys.exit(1)
                    write_manifest(out_dir)

    if not args.lijst:
        total = write_manifest(out_dir)
        log(f"\nKlaar: {total} routes in {(out_dir / 'routes.json').resolve()} "
            f"(nieuw {stats['ok']}, geen rondje {stats['geen']}, al gedaan {stats['cache']})")


if __name__ == "__main__":
    main()
