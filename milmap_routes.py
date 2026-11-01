#!/usr/bin/env python3
"""
Haalt wandel-, fiets-, MTB- en hardlooproutes in Nederland, België, Duitsland, Oostenrijk, Engeland, Spanje en Frankrijk uit OpenStreetMap
en schrijft ze als GPX + een manifest (routes.json) voor import in Milmap.

Gebruik:
    python3 milmap_routes.py                 # 100 per type, output in ./milmap_routes
    python3 milmap_routes.py --count 50 --out ./export
    python3 milmap_routes.py --types hardlopen --per-provincie --out ./export-hardlopen
    python3 -u milmap_routes.py --country de --count 60 --out ./export-de     # alle 16 deelstaten
    python3 -u milmap_routes.py --country nl --types fietsen --per-provincie --out ./export-fietsen

Hervatbaar: het resultaat per regio (provincie / deelstaat) staat in <out>/.cache. Een klare regio wordt
overgeslagen, een regio die tegen --count aan zat wordt bij een hoger --count aangevuld, en routes.json
wordt na elke regio opnieuw opgebouwd.

Hardlopen heeft veel minder routes in OSM dan wandelen (tientallen i.p.v. duizenden):
exporteer dat type apart, zodat je de wandel-/MTB-export niet opnieuw hoeft te draaien.

Alleen de Python-standaardbibliotheek, geen extra packages nodig.
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
from collections import defaultdict
from pathlib import Path
from xml.sax.saxutils import escape

ENDPOINTS = [
    "https://overpass-api.de/api/interpreter",
    "https://lz4.overpass-api.de/api/interpreter",
    "https://z.overpass-api.de/api/interpreter",
]
USER_AGENT = "MilmapRouteImporter/1.0 (+https://milmap.nl)"
NL_AREA = 3600047796
PROVINCES = {
    "groningen": "NL-GR", "friesland": "NL-FR", "drenthe": "NL-DR",
    "overijssel": "NL-OV", "flevoland": "NL-FL", "gelderland": "NL-GE",
    "utrecht": "NL-UT", "noord-holland": "NL-NH", "zuid-holland": "NL-ZH",
    "zeeland": "NL-ZE", "noord-brabant": "NL-NB", "limburg": "NL-LI",
}
BUNDESLAENDER = {
    "baden-wuerttemberg": "DE-BW", "bayern": "DE-BY", "berlin": "DE-BE",
    "brandenburg": "DE-BB", "bremen": "DE-HB", "hamburg": "DE-HH",
    "hessen": "DE-HE", "mecklenburg-vorpommern": "DE-MV", "niedersachsen": "DE-NI",
    "nordrhein-westfalen": "DE-NW", "rheinland-pfalz": "DE-RP", "saarland": "DE-SL",
    "sachsen": "DE-SN", "sachsen-anhalt": "DE-ST", "schleswig-holstein": "DE-SH",
    "thueringen": "DE-TH",
}
BELGIAN_PROVINCES = {
    "antwerpen": "BE-VAN", "limburg": "BE-VLI", "oost-vlaanderen": "BE-VOV",
    "vlaams-brabant": "BE-VBR", "west-vlaanderen": "BE-VWV",
    "henegouwen": "BE-WHT", "luik": "BE-WLG", "luxemburg": "BE-WLX",
    "namen": "BE-WNA", "waals-brabant": "BE-WBR", "brussel": "BE-BRU",
}
# land → (ISO-code voor TrailRoute.country, regio's). Buiten Nederland wordt altijd per regio opgehaald.
AUSTRIAN_STATES = {
    "burgenland": "AT-1", "kaernten": "AT-2", "niederoesterreich": "AT-3",
    "oberoesterreich": "AT-4", "salzburg": "AT-5", "steiermark": "AT-6",
    "tirol": "AT-7", "vorarlberg": "AT-8", "wien": "AT-9",
}
SPANISH_REGIONS = {
    "andalucia": "ES-AN", "aragon": "ES-AR", "asturias": "ES-AS",
    "baleares": "ES-IB", "canarias": "ES-CN", "cantabria": "ES-CB",
    "castilla-la-mancha": "ES-CM", "castilla-y-leon": "ES-CL", "catalunya": "ES-CT",
    "extremadura": "ES-EX", "galicia": "ES-GA", "madrid": "ES-MD",
    "murcia": "ES-MC", "navarra": "ES-NC", "pais-vasco": "ES-PV",
    "la-rioja": "ES-RI", "valencia": "ES-VC", "ceuta": "ES-CE", "melilla": "ES-ML",
}
FRENCH_REGIONS = {
    "auvergne-rhone-alpes": "FR-ARA", "bourgogne-franche-comte": "FR-BFC",
    "bretagne": "FR-BRE", "centre-val-de-loire": "FR-CVL", "corse": "FR-20R",
    "grand-est": "FR-GES", "hauts-de-france": "FR-HDF", "ile-de-france": "FR-IDF",
    "normandie": "FR-NOR", "nouvelle-aquitaine": "FR-NAQ", "occitanie": "FR-OCC",
    "pays-de-la-loire": "FR-PDL", "provence-alpes-cote-d-azur": "FR-PAC",
    "guadeloupe": "GP", "martinique": "MQ", "guyane": "FR-GF",
    "la-reunion": "FR-RE", "mayotte": "YT",
}
SWISS_CANTONS = {
    "aargau": "CH-AG", "appenzell-innerrhoden": "CH-AI", "appenzell-ausserrhoden": "CH-AR",
    "bern": "CH-BE", "basel-landschaft": "CH-BL", "basel-stadt": "CH-BS",
    "fribourg": "CH-FR", "geneve": "CH-GE", "glarus": "CH-GL", "graubuenden": "CH-GR",
    "jura": "CH-JU", "luzern": "CH-LU", "neuchatel": "CH-NE", "nidwalden": "CH-NW",
    "obwalden": "CH-OW", "st-gallen": "CH-SG", "schaffhausen": "CH-SH",
    "solothurn": "CH-SO", "schwyz": "CH-SZ", "thurgau": "CH-TG", "ticino": "CH-TI",
    "uri": "CH-UR", "vaud": "CH-VD", "valais": "CH-VS", "zug": "CH-ZG", "zuerich": "CH-ZH",
}
ITALIAN_REGIONS = {
    "piemonte": "IT-21", "valle-d-aosta": "IT-23", "lombardia": "IT-25",
    "trentino-alto-adige": "IT-32", "veneto": "IT-34", "friuli-venezia-giulia": "IT-36",
    "liguria": "IT-42", "emilia-romagna": "IT-45", "toscana": "IT-52", "umbria": "IT-55",
    "marche": "IT-57", "lazio": "IT-62", "abruzzo": "IT-65", "molise": "IT-67",
    "campania": "IT-72", "puglia": "IT-75", "basilicata": "IT-77", "calabria": "IT-78",
    "sicilia": "IT-82", "sardegna": "IT-88",
}
PORTUGUESE_REGIONS = {
    "aveiro": "PT-01", "beja": "PT-02", "braga": "PT-03", "braganca": "PT-04",
    "castelo-branco": "PT-05", "coimbra": "PT-06", "evora": "PT-07", "faro": "PT-08",
    "guarda": "PT-09", "leiria": "PT-10", "lisboa": "PT-11", "portalegre": "PT-12",
    "porto": "PT-13", "santarem": "PT-14", "setubal": "PT-15", "viana-do-castelo": "PT-16",
    "vila-real": "PT-17", "viseu": "PT-18", "madeira": "PT-20", "acores": "PT-30",
}
NORWEGIAN_COUNTIES = {
    "oslo": "NO-03", "rogaland": "NO-11", "moere-og-romsdal": "NO-15", "nordland": "NO-18",
    "oestfold": "NO-31", "akershus": "NO-32", "buskerud": "NO-33", "innlandet": "NO-34",
    "vestfold": "NO-39", "telemark": "NO-40", "agder": "NO-42", "vestland": "NO-46",
    "troendelag": "NO-50", "troms": "NO-55", "finnmark": "NO-56", "svalbard": "NO-21",
}
CZECH_REGIONS = {
    "praha": "CZ-10", "stredocesky": "CZ-20", "jihocesky": "CZ-31", "plzensky": "CZ-32",
    "karlovarsky": "CZ-41", "ustecky": "CZ-42", "liberecky": "CZ-51",
    "kralovehradecky": "CZ-52", "pardubicky": "CZ-53", "vysocina": "CZ-63",
    "jihomoravsky": "CZ-64", "olomoucky": "CZ-71", "zlinsky": "CZ-72",
    "moravskoslezsky": "CZ-80",
}
COUNTRIES = {
    "nl": ("NL", PROVINCES), "de": ("DE", BUNDESLAENDER),
    "be": ("BE", BELGIAN_PROVINCES), "at": ("AT", AUSTRIAN_STATES),
    "eng": ("GB", {"england": "GB-ENG"}),
    "es": ("ES", SPANISH_REGIONS), "fr": ("FR", FRENCH_REGIONS),
    "ch": ("CH", SWISS_CANTONS), "it": ("IT", ITALIAN_REGIONS),
    "lu": ("LU", {"luxemburg": "LU"}), "pt": ("PT", PORTUGUESE_REGIONS),
    "si": ("SI", {"slovenie": "SI"}), "no": ("NO", NORWEGIAN_COUNTIES),
    "cz": ("CZ", CZECH_REGIONS), "sct": ("GB", {"schotland": "GB-SCT"}),
}
ATTRIBUTION = "© OpenStreetMap contributors, ODbL 1.0"
SKIP_ROLES = {"alternative", "excursion", "approach", "connection", "link"}
GAP_METERS = 75
# Eén geometriecall is verreweg de duurste stap. Zestig relaties per aanvraag
# houdt de antwoorden beheersbaar en scheelt vier keer zoveel round-trips.
# Via env-vars kan productie zonder codewijziging worden bijgesteld.
BATCH = max(1, int(os.environ.get("MILMAP_ROUTE_BATCH", "60")))
BATCH_PAUSE = max(0, float(os.environ.get("MILMAP_ROUTE_PAUSE", "0.5")))
# Netwerken die we voorrang geven: officieel bewegwijzerd (wandel- en fietsnetwerken).
PREFERRED_NETWORKS = {"lwn", "rwn", "lcn", "rcn", "ncn"}
# Eerdere runs (kale lijsten in .cache) gebruikten deze lengtebereiken en een maximum van 100.
LEGACY_FILTERS = {"wandelen": (4, 30), "mtb": (8, 80)}
LEGACY_CAP = 100

# Namen waaraan we een hardlooproute herkennen als de maker er geen route=running van maakte.
RUN_NAME = "hardloop|hardloper|running|jogg|trimbaan|trimpad|trimroute|trimparcours|trail ?run|runroute|parkrun|laufstrecke|laufrunde|laufpfad|lauftreff|waldlauf|trimm"

# Per type de Overpass-selectors (samengevoegd met een union) en het lengtebereik dat we accepteren.
PROFILES = {
    "wandelen": {
        "selectors": ['relation["type"="route"]["route"="hiking"]["name"]["network:type"!="node_network"]'],
        "min_km": 3,
        "max_km": 40,
        "target_km": 12,
    },
    "fietsen": {
        "selectors": ['relation["type"="route"]["route"="bicycle"]["name"]["network:type"!="node_network"]'],
        "min_km": 10,
        "max_km": 120,
        "target_km": 40,
    },
    "mtb": {
        "selectors": ['relation["type"="route"]["route"="mtb"]["name"]["network:type"!="node_network"]'],
        "min_km": 5,
        "max_km": 100,
        "target_km": 30,
    },
    "hardlopen": {
        # route=hiking blijft bewust bij "wandelen": één OSM-relatie kan maar één type hebben.
        "selectors": [
            'relation["type"="route"]["route"~"^(running|jogging)$"]',
            f'relation["type"="route"]["route"~"^(foot|fitness_trail)$"]["name"~"{RUN_NAME}",i]',
        ],
        # Een trimbaan of één parkrun-ronde is al snel rond de 1 km.
        "min_km": 1,
        "max_km": 60,
        "target_km": 8,
        # Zegt de naam/distance-tag "10 km" en is er maar 1,9 km pad gemapt, dan is de relatie onvolledig.
        "check_claimed_km": True,
    },
}


def log(message, error=False):
    print(message, file=sys.stderr if error else sys.stdout, flush=True)


def post(url, data):
    """POST naar Overpass. Oude Python/LibreSSL-builds (macOS) kunnen geen TLS naar sommige hosts: dan curl."""
    try:
        req = urllib.request.Request(url, data=data, headers={"User-Agent": USER_AGENT})
        with urllib.request.urlopen(req, timeout=300) as resp:
            return resp.read().decode("utf-8")
    except urllib.error.URLError as exc:
        if not (isinstance(exc.reason, ssl.SSLError) and shutil.which("curl")):
            raise
        out = subprocess.run(
            ["curl", "-sS", "--fail", "-m", "300", "-A", USER_AGENT, "--data-binary", "@-", url],
            input=data, capture_output=True, check=True,
        )
        return out.stdout.decode("utf-8")


def overpass(query, retries=6):
    data = urllib.parse.urlencode({"data": query}).encode()
    last_error = None
    for attempt in range(retries):
        for url in list(ENDPOINTS):
            try:
                result = json.loads(post(url, data))
                # Een remark bij time-out/geheugentekort betekent een onvolledig (of leeg) antwoord.
                remark = result.get("remark") or ""
                if re.search(r"error|timed out|out of memory|too many", remark, re.I):
                    raise RuntimeError(f"Overpass-opmerking: {remark[:120]}")
                # Een gezonde mirror eerst proberen bij de volgende aanvraag.
                # Dit voorkomt dat iedere batch opnieuw langs dezelfde 502-hosts gaat.
                if ENDPOINTS[0] != url:
                    ENDPOINTS.remove(url)
                    ENDPOINTS.insert(0, url)
                return result
            except Exception as exc:
                last_error = exc
                print(f"  ! {url} faalde ({str(exc)[:140]}), volgende proberen...", file=sys.stderr)
        wait = min(30 * (attempt + 1), 150)
        print(f"  … Overpass bezet, over {wait}s opnieuw", file=sys.stderr, flush=True)
        time.sleep(wait)
    raise RuntimeError(f"Overpass onbereikbaar: {last_error}")


def haversine(a, b):
    lat1, lon1, lat2, lon2 = map(math.radians, (a[0], a[1], b[0], b[1]))
    h = math.sin((lat2 - lat1) / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin((lon2 - lon1) / 2) ** 2
    return 2 * 6371000 * math.asin(math.sqrt(h))


def is_candidate(tags):
    if tags.get("type") != "route" or not tags.get("name"):
        return False
    if tags.get("network:type") == "node_network":
        return False
    if re.fullmatch(r"\s*\d+\s*[-–]\s*\d+\s*", tags.get("name", "")):
        return False
    if tags.get("state") in {"proposed", "disused"}:
        return False
    return True


def fetch_candidates(profile, iso=None):
    area = (
        f'area["{"ISO3166-1" if len(iso) == 2 else "ISO3166-2"}"="{iso}"]["boundary"="administrative"]->.a;' if iso
        else f"area({NL_AREA})->.a;"
    )
    union = "".join(f"{selector}(area.a);" for selector in profile["selectors"])
    query = f"""
    [out:json][timeout:240];
    {area}
    ({union});
    out tags bb;
    .a out ids;
    """
    elements = overpass(query).get("elements", [])
    if not any(e.get("type") == "area" for e in elements):
        raise RuntimeError(f"OSM-regio niet gevonden: {iso or 'NL'}")
    return [e for e in elements if "bounds" in e and is_candidate(e.get("tags", {}))]


def spread_order(candidates, cell_deg=0.2):
    """Verdeel kandidaten over een raster zodat routes over het hele gebied gespreid zijn."""
    cells = defaultdict(list)
    for c in candidates:
        b = c["bounds"]
        lat = (b["minlat"] + b["maxlat"]) / 2
        lon = (b["minlon"] + b["maxlon"]) / 2
        cells[(int(lat / cell_deg), int(lon / cell_deg))].append(c)
    for items in cells.values():
        items.sort(key=lambda c: (c["tags"].get("network") not in PREFERRED_NETWORKS, c["id"]))
    queues = list(cells.values())
    ordered = []
    while any(queues):
        for q in queues:
            if q:
                ordered.append(q.pop(0))
    return ordered


def fetch_geometry(ids):
    id_list = ",".join(str(i) for i in ids)
    query = f"[out:json][timeout:240];relation(id:{id_list});out geom;"
    try:
        elements = overpass(query, retries=2).get("elements", [])
        return {e["id"]: e for e in elements if e["type"] == "relation"}
    except RuntimeError:
        # Een uitzonderlijk zware batch kan de geheugenlimiet van een mirror raken.
        # Splits dan automatisch; reeds afgeronde helften hoeven niet opnieuw.
        if len(ids) <= 15:
            raise
        middle = len(ids) // 2
        return {**fetch_geometry(ids[:middle]), **fetch_geometry(ids[middle:])}


def way_lines(relation):
    lines = []
    for m in relation.get("members", []):
        if m.get("type") != "way" or m.get("role", "") in SKIP_ROLES:
            continue
        pts = [(p["lat"], p["lon"]) for p in m.get("geometry", []) if p]
        if len(pts) >= 2:
            lines.append(pts)
    return lines


def stitch(lines):
    """Koppel losse ways tot doorlopende segmenten; gaten > GAP_METERS worden een nieuw segment."""
    remaining = [list(l) for l in lines]
    segments = []
    current = remaining.pop(0)
    while remaining:
        end = current[-1]
        best, best_dist, best_rev = None, float("inf"), False
        for i, line in enumerate(remaining):
            for rev, point in ((False, line[0]), (True, line[-1])):
                d = haversine(end, point)
                if d < best_dist:
                    best, best_dist, best_rev = i, d, rev
        line = remaining.pop(best)
        if best_rev:
            line.reverse()
        if best_dist <= GAP_METERS:
            current.extend(line[1:] if best_dist < 1 else line)
        else:
            segments.append(current)
            current = line
    segments.append(current)
    return segments


def length_km(segments):
    return sum(haversine(s[i], s[i + 1]) for s in segments for i in range(len(s) - 1)) / 1000


def claimed_km(tags):
    """Lengte die de maker zelf opgaf: de distance-tag, of "10 km" in de naam."""
    m = re.fullmatch(r"\s*(\d+(?:[.,]\d+)?)\s*(km|m)?\s*", tags.get("distance", ""), re.I)
    if m:
        value = float(m.group(1).replace(",", "."))
        return value / 1000 if (m.group(2) or "").lower() == "m" else value
    m = re.search(r"(\d+(?:[.,]\d+)?)\s*km\b", tags.get("name", ""), re.I)
    return float(m.group(1).replace(",", ".")) if m else None


def slugify(text):
    text = unicodedata.normalize("NFKD", text.replace("ß", "ss")).encode("ascii", "ignore").decode("ascii")
    text = re.sub(r"[^\w\s-]", "", text.lower())
    return re.sub(r"[\s_-]+", "-", text).strip("-")[:60] or "route"


def to_gpx(name, osm_id, kind, segments, tags):
    desc = tags.get("description") or tags.get("note") or ""
    lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<gpx version="1.1" creator="Milmap" xmlns="http://www.topografix.com/GPX/1/1">',
        "  <metadata>",
        f"    <name>{escape(name)}</name>",
        f'    <copyright author="OpenStreetMap contributors"><license>https://opendatacommons.org/licenses/odbl/1-0/</license></copyright>',
        f'    <link href="https://www.openstreetmap.org/relation/{osm_id}"><text>OSM relatie {osm_id}</text></link>',
        "  </metadata>",
        "  <trk>",
        f"    <name>{escape(name)}</name>",
    ]
    if desc:
        lines.append(f"    <desc>{escape(desc)}</desc>")
    lines.append(f"    <type>{kind}</type>")
    for seg in segments:
        lines.append("    <trkseg>")
        lines.extend(f'      <trkpt lat="{lat:.7f}" lon="{lon:.7f}"/>' for lat, lon in seg)
        lines.append("    </trkseg>")
    lines += ["  </trk>", "</gpx>", ""]
    return "\n".join(lines)


def plausible(c, profile):
    """Een route kan niet veel langer zijn dan de diagonaal van zijn omhullende: scheelt geometrie-downloads."""
    b = c["bounds"]
    diagonal_km = haversine((b["minlat"], b["minlon"]), (b["maxlat"], b["maxlon"])) / 1000
    return diagonal_km <= profile["max_km"] * 1.5


def read_state(path, kind, profile):
    signature = [profile["min_km"], profile["max_km"]]
    if not path.exists():
        return {"filters": signature, "complete": False, "routes": []}
    data = json.loads(path.read_text(encoding="utf-8"))
    if isinstance(data, list):
        legacy = LEGACY_FILTERS.get(kind)
        data = {"filters": list(legacy) if legacy else None, "complete": len(data) < LEGACY_CAP, "routes": data}
    if data.get("filters") != signature:
        # Ander lengtebereik: opnieuw kijken wat er nu wel binnen valt (bestaande routes blijven).
        data["filters"], data["complete"] = signature, False
    return data


def write_state(path, state):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(state, ensure_ascii=False), encoding="utf-8")


def cached_routes(out_dir):
    for path in sorted((out_dir / ".cache").glob("*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        yield from (data if isinstance(data, list) else data.get("routes", []))


def write_manifest(out_dir):
    """Bouw routes.json uit alle caches; routes die er al in staan blijven (nooit iets weggooien)."""
    manifest_path = out_dir / "routes.json"
    routes = {}
    if manifest_path.exists():
        for r in json.loads(manifest_path.read_text(encoding="utf-8")).get("routes", []):
            routes[r["osm_relation"]] = r
    for r in cached_routes(out_dir):
        routes[r["osm_relation"]] = r
    manifest = {
        "generated": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "attribution": ATTRIBUTION,
        "license": "https://opendatacommons.org/licenses/odbl/1-0/",
        "routes": list(routes.values()),
    }
    tmp = manifest_path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(manifest_path)
    return len(routes)


def collect(kind, profile, count, out_dir, country="NL", region=None, iso=None, seen_ids=None, used_slugs=None):
    label = f"{country}/{kind}/{region}" if region else f"{country}/{kind}"
    seen_ids = seen_ids if seen_ids is not None else set()
    used_slugs = used_slugs if used_slugs is not None else set()

    key = "-".join([kind, region or "nederland"] if country == "NL" else [country.lower(), kind, region])
    cache = out_dir / ".cache" / f"{key}.json"
    state = read_state(cache, kind, profile)
    accepted = state["routes"]
    if state["complete"] or len(accepted) >= count:
        log(f"[{label}] overgeslagen ({len(accepted)} routes, {'klaar' if state['complete'] else 'maximum bereikt'})")
        return accepted

    log(f"\n[{label}] kandidaten ophalen ({len(accepted)} al aanwezig)...")
    raw = fetch_candidates(profile, iso)
    if accepted and not raw:
        # Een fallback-mirror kan een lege, syntactisch geldige respons geven
        # terwijl dezelfde regio aantoonbaar routes bevat. Nooit zo'n cache als
        # voltooid markeren; later hervatten via een gezonde mirror.
        raise RuntimeError(f"Verdacht leeg antwoord voor {label} met {len(accepted)} bestaande routes")
    pool = [c for c in raw if c["id"] not in seen_ids and plausible(c, profile)]
    candidates = spread_order(pool, cell_deg=0.05 if region else 0.2)
    log(f"[{label}] {len(raw)} benoemde routes, {len(candidates)} kandidaten")

    folder = out_dir / kind / region if region else out_dir / kind
    folder.mkdir(parents=True, exist_ok=True)

    for start in range(0, len(candidates), BATCH):
        if len(accepted) >= count:
            break
        batch = candidates[start:start + BATCH]
        geoms = fetch_geometry([c["id"] for c in batch])
        for c in batch:
            if len(accepted) >= count:
                break
            rel = geoms.get(c["id"])
            lines = way_lines(rel) if rel else []
            if not lines:
                continue
            segments = stitch(lines)
            km = length_km(segments)
            if not profile["min_km"] <= km <= profile["max_km"]:
                continue
            main = max(segments, key=len)
            if len(segments) > 3 and len(main) < 0.6 * sum(len(s) for s in segments):
                continue
            claimed = claimed_km(c["tags"]) if profile.get("check_claimed_km") and c["tags"].get("network") != "parkrun" else None
            if claimed and km < 0.6 * claimed:
                # Parkruns blijven buiten deze controle: die lopen meerdere ronden van het gemapte pad.
                log(f"  - {c['tags']['name']}: {km:.1f} km pad voor een route van {claimed:g} km, overgeslagen")
                continue

            seen_ids.add(c["id"])
            tags = c["tags"]
            slug = slugify(tags["name"])
            if slug in used_slugs:
                slug = f"{slug}-{c['id']}"
            used_slugs.add(slug)

            (folder / f"{slug}.gpx").write_text(to_gpx(tags["name"], c["id"], kind, segments, tags), encoding="utf-8")
            b = c["bounds"]
            accepted.append({
                "slug": slug,
                "name": tags["name"],
                "type": kind,
                "country": country,
                "distance_km": round(km, 1),
                "segments": len(segments),
                "is_loop": haversine(segments[0][0], segments[-1][-1]) < 300,
                "start": {"lat": segments[0][0][0], "lon": segments[0][0][1]},
                "bbox": [b["minlon"], b["minlat"], b["maxlon"], b["maxlat"]],
                "network": tags.get("network"),
                "operator": tags.get("operator"),
                "website": tags.get("website"),
                "province": region,
                "gpx_file": str((folder / f"{slug}.gpx").relative_to(out_dir)),
                "osm_relation": c["id"],
                "source_url": f"https://www.openstreetmap.org/relation/{c['id']}",
                "attribution": ATTRIBUTION,
            })
            log(f"  ✓ {len(accepted):3d}  {tags['name']} ({km:.1f} km)")
        write_state(cache, state)
        if BATCH_PAUSE:
            time.sleep(BATCH_PAUSE)

    # Minder dan --count gehaald betekent: alle kandidaten zijn bekeken, de regio is leeg.
    state["complete"] = len(accepted) < count
    write_state(cache, state)
    if state["complete"]:
        log(f"[{label}] klaar: {len(accepted)} routes voldeden aan de filters")
    return accepted


def selected_regions(countries, per_region, only=None):
    """[(land, regio, iso)]; Buiten Nederland altijd per regio (Engeland gebruikt uitsluitend GB-ENG)."""
    out = []
    for name in countries:
        code, regions = COUNTRIES[name]
        if name == "nl" and not per_region:
            out.append((code, None, None))
        else:
            out += [(code, region, iso) for region, iso in regions.items() if not only or region in only]
    return out


def count_only(kinds, countries):
    print(f"{'regio':<26}" + "".join(f"{k:>11}" for k in kinds) + "   (benoemde routes in OSM, voor lengtefilter)")
    for code, region, iso in selected_regions(countries, True):
        counts = [len(fetch_candidates(PROFILES[k], iso)) for k in kinds]
        print(f"{code + '/' + region:<26}" + "".join(f"{c:>11}" for c in counts))
        time.sleep(2)


def preload(out_dir):
    """Reeds geëxporteerde relaties en slugs uit eerdere runs, zodat niets dubbel komt."""
    seen, slugs = defaultdict(set), defaultdict(set)
    for r in cached_routes(out_dir):
        seen[r["type"]].add(r["osm_relation"])
        slugs[r["type"]].add(r["slug"])
    return seen, slugs


def main():
    parser = argparse.ArgumentParser(description="OSM-routes naar GPX voor Milmap")
    parser.add_argument("--count", type=int, default=100, help="aantal routes per type (per regio met --per-provincie buiten Nederland altijd per regio)")
    parser.add_argument("--per-provincie", action="store_true", help="--count routes per provincie ophalen (buiten Nederland: altijd per regio)")
    parser.add_argument("--tel", action="store_true", help="alleen tellen hoeveel routes er per regio zijn")
    parser.add_argument("--types", nargs="+", choices=list(PROFILES), default=list(PROFILES),
                        help="welke routetypes (standaard alle)")
    parser.add_argument("--country", choices=[*COUNTRIES, "all"], default="nl", help="land (standaard nl)")
    parser.add_argument("--regions", help="alleen deze provincies/deelstaten, kommagescheiden (bv. bayern,saarland)")
    parser.add_argument("--out", default="milmap_routes")
    args = parser.parse_args()

    countries = list(COUNTRIES) if args.country == "all" else [args.country]
    if args.tel:
        count_only(args.types, countries)
        return

    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)
    only = set(args.regions.split(",")) if args.regions else None
    seen, slugs = preload(out_dir)
    failed, in_a_row = [], 0
    for kind in args.types:
        for code, region, iso in selected_regions(countries, args.per_provincie, only):
            try:
                collect(kind, PROFILES[kind], args.count, out_dir, code, region, iso, seen[kind], slugs[kind])
                in_a_row = 0
            except RuntimeError as exc:
                failed.append(f"{code}/{kind}/{region or 'nederland'}")
                in_a_row += 1
                log(f"[{code}/{kind}/{region}] overgeslagen: {exc}", error=True)
            write_manifest(out_dir)
            if in_a_row >= 3:
                log("Overpass lijkt onbereikbaar: gestopt. Draai hetzelfde commando later opnieuw, dan gaat het verder.", error=True)
                sys.exit(1)

    total = write_manifest(out_dir)
    log(f"\nKlaar: {total} routes in {(out_dir / 'routes.json').resolve()}")
    if failed:
        log(f"Niet gelukt (opnieuw draaien om aan te vullen): {', '.join(failed)}", error=True)
        sys.exit(1)


if __name__ == "__main__":
    main()
