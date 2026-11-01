# Ontwerp: route-ontdekking in de routeplanner (2026-10-30)

Vervangt het missie-gebonden groepstocht-ontwerp als eerstvolgende stap (zie [groepstocht-sprints.md](groepstocht-sprints.md), op hold). Er is nog niets gebouwd.

## 0. Wat de gebruiker wil

> "Als ik bijvoorbeeld een berg wil beklimmen, zie ik in de routeplanner of er routes zijn, kan ik die selecteren om te bekijken, en gebruiken in mijn eigen profiel."

Dus: zoeken op een plek of berg → bestaande routes daar zien → een route bekijken (kaart, afstand, hoogteprofiel) → opslaan en gebruiken in het eigen account. Dit hoort in de **map-omgeving** (routeplanner), niet in missies. Het past bij de map-centric ombouw: kaart = centrum, missie en groep eromheen.

## 1. Wat er al is (onderzoek 2026-10-30)

| Behoefte | Bestaand | Opmerking |
|---|---|---|
| Plek zoeken | `SearchOverlay.vue` + `/api/v1/geo/search` (Mapbox Geocoding v5) | alleen een punt; `types`/`bbox` niet doorgegeven; of "Mont Blanc" als berg terugkomt is niet getest |
| Bergtoppen | Overpass-proxy `POST /api/v1/geo/overpass` (cache 7 dagen, 30/min); `TerrainInfo.vue` vraagt `natural=peak` met `ele` | geen peaks-tabel, geen query op `route=hiking` of hutten |
| Planner | `RoutePlannerPanel.vue` in een sheet in `View.vue`; Valhalla (publieke FOSSGIS-demo) met profielen wandelen/fiets/mtb; hoogteprofiel `RoutePlannerProfile.vue` | geen lijst of tab met bestaande routes |
| Openbaar delen | `public_profiles`, `is_public` op `route_maps`/`activities`, `PublicRoutePresenter` (whitelist), "Gebruik deze route" (`cloneInto`, `cloned_from_*`) | gebouwd 2026-10-29, **niet gedeployed**; alleen per profiel opvraagbaar |
| Voorbeeld op kaart | `RouteMapPreview.vue` (interactief), `ActivityTrace` (statisch plaatje) | de publieke sheet heeft geen hoogtegrafiek |

## 2. Wat ontbreekt (en waarom het ertoe doet)

1. **Zoeken op locatie.** `route_maps` heeft geen bbox, centroid of geometriekolom en dus geen index. Er is geen endpoint "routes bij deze plek". (`activities.start_lat/lon` is bewust geen optie: dat omzeilt de 200 m privacy-trim.)
2. **Opgeslagen routes zijn te grof.** De planner bewaart alleen `no, title, lat, lon, mgrs` per waypoint (max 40). Het gesnapte pad (`segments`), de sport, de totalen en de hoogtes **gaan verloren** bij `saveAsRoutekaart` (`View.vue:9673`). GPX-import houdt max. ~30 punten. Gevolg: een gevonden community-route zou als rechte lijnen tussen waypoints getoond worden, zonder hoogteprofiel. `total_elevation` is waarschijnlijk vaak 0, want niets schrijft `elevationGain`.
3. **Cold start.** Er is geen seed-content. Publiceren vraagt een aan staand profiel én premium; er is geen globale lijst.
4. **Bron en licentie.** Alleen `cloned_from_*` (herkomst binnen MilMap). Voor OSM-data (ODbL) is een bron/attributie-veld verplicht.
5. **Moderatie.** Geen melden, geen admin-beheer van publieke routes. Zodra routes vindbaar worden, is dat nodig.
6. **Opslagbeperkingen voor gratis gebruikers.** Klonen vraagt premium `route_maps` en `FREE_MAP_LIMIT` = 1 kaart (402). Voor een ontdek-ingang als groeimotor is dat te strak (zie beslissingen).

## 3. Het ontwerp

### 3.1 Gebruikersflow
```
Planner openen (kaart)  ──►  zoek "Mont Blanc" (of tik een top op de kaart)
        │
        ▼
Tab "Ontdekken" in de planner
  Chips: [Wandelen] [Fiets] [MTB] [Rondje]   Afstand ▾   Moeilijkheid ▾   Straal ▾
  ┌─────────────────────────────────────────────────────────────┐
  │ [thumb]  Tour du Mont Blanc – etappe 1      Wandelen · T3    │
  │          18,4 km · ↑ 1.240 m · ~6u 40   ·  door @anna       │
  │ [thumb]  Normaalroute Goûter (Tête Rousse)  Bergwandelen · T4│
  │          ... bron: OpenStreetMap                              │
  │ [ Plan zelf een route naar de top ]                          │
  └─────────────────────────────────────────────────────────────┘
        │ tik op een kaart
        ▼
Voorbeeld op de kaart: lijn, start/einde, hoogteprofiel, bron-badge
  [Gebruik in planner]   [Opslaan in mijn routes]   [Navigeren]
```
- **Gebruik in planner:** laadt de route als bewerkbare route in de planner.
- **Opslaan:** klonen naar een eigen kaart (bestaande `cloneInto`), met herkomst. Daarna staat de route in de eigen routelijst en (als de gebruiker dat wil) op het openbare profiel via de bestaande publiceer-schakelaar.
- **Plan zelf een route naar de top:** voor toppen zonder routes. Zet de top als bestemming, stelt startpunten voor (parkeerplaats, hut, trailhead uit Overpass) en routeert met Valhalla.
- Ingang ook vanuit de zoekresultaat-actiesheet (`onSearchSelect` → actiesheet): "Routes hier ontdekken".

### 3.2 Bronnen van routes (cold start oplossen)
| Bron | Wat | Voordeel | Let op |
|---|---|---|---|
| **MilMap-gebruikers** | gepubliceerde `route_maps` met bbox | groeit met gebruik, echte ervaringen | leeg bij start; moderatie nodig |
| **OpenStreetMap-routes** | Overpass `relation[route~"hiking\|foot\|bicycle\|mtb"]` rond de plek; geometrie pas ophalen als je er één opent; moeilijkheid uit `sac_scale`/`mtb:scale` | direct dekking wereldwijd, gratis | ODbL-attributie ("© OpenStreetMap-bijdragers"); publieke Overpass is traag/begrensd, dus cache op een geraster zoekpunt; later eigen instantie |
| **Zelf plannen** | Valhalla-route naar de top | werkt altijd | geen "bewezen" route; kwaliteit hangt van OSM-paden af |

Aanbeveling: lanceer met **OSM + zelf plannen**, en voeg community-routes toe zodra Fase 0 klaar is. Dan is de feature vanaf dag 1 nuttig.

### 3.3 Toppen vindbaar maken
- Fase 1: Mapbox-geocoder + Overpass `natural=peak` in de buurt (bestaat al in `TerrainInfo.vue`).
- Fase 3: eigen `peaks`-tabel (naam, hoogte, lat/lon, osm_id, wikidata) uit een OSM-extract. Geeft directe autocomplete met hoogte en werkt zonder Overpass. Eigen import, ODbL-attributie.

### 3.4 Datamodel (route_maps uitbreiden, gewone kolommen i.v.m. SQLite-tests)
- `bbox_min_lat, bbox_min_lon, bbox_max_lat, bbox_max_lon` (+ index met `is_public`)
- `sport` (hike|bike|mtb|run), `difficulty` (nullable), `geometry` (versleutelde of gewone encoded polyline, 100–400 punten), `elevation_profile` (compact), `ascent_m`, `descent_m`
- `source` (milmap|osm|import), `source_ref` (bijv. OSM-relation-id), `attribution` (tekst)
- `routes_reports` (route_id, reporter_id, reden, status) voor melden
- Backfill: bbox uit bestaande `locations`.

### 3.5 API (concept)
- `GET /api/v1/routes/discover?lat=&lon=&radius_km=&sport=&q=&min_km=&max_km=` → samenvattingen via `PublicRoutePresenter` (whitelist, max 30), met `preview` (polyline), cijfers, bron. Cache per gesnapt gebied.
- `GET /api/v1/routes/discover/osm?lat=&lon=&radius_km=&sport=` → lijst van OSM-relaties (tags, bbox, lengte indien beschikbaar); `GET .../osm/{relationId}` → geometrie (op aanvraag, 7 dagen cache).
- Bestaand hergebruik: `POST /profiles/{handle}/routes/{id}/clone`, nieuw: `POST /routes/discover/osm/{relationId}/save` (maakt een `RouteMap` met `source=osm`).
- Frontend: nieuwe `RouteDiscoverTab.vue` in de planner, `RouteDiscoverService.js`, hergebruik `RouteThumb`, `RoutePlannerProfile`, `RouteMapPreview`.

## 4. Fasering (grove schatting, niet gevalideerd)

**Fase 0, voorwerk, ~3–5 dagen**
- Planner-opslag laten bewaren: gesnapt pad, sport, totalen, hoogtes; GPX-import met volledige track; `total_elevation` echt vullen.
- Kolommen uit §3.4 + backfill; presenter geeft geometrie en profiel mee.
- Het openbare-profiel-werk (gebouwd 2026-10-29) deployen; dat is de basis van "Gebruik deze route".

**Fase 1, ~5–8 dagen:** tab "Ontdekken" met community-routes, kaart-voorbeeld met hoogteprofiel, "Opslaan"/"Gebruik in planner", ingang vanuit zoekresultaat.

**Fase 2, ~5–8 dagen:** OSM-routes (lijst + geometrie op aanvraag, moeilijkheid, attributie, cache op geraster).

**Fase 3, ~5–8 dagen:** "Plan zelf een route naar de top" met startpuntvoorstellen; eigen `peaks`-tabel; melden + admin-overzicht.

**Fase 4, later:** groep op een route, **kaart-gebonden** (live locatie, uitnodigingen en chat bestaan al per kaart; geen missie nodig). Hergebruik van de eerdere groepstocht-gedachte, maar als actie op een opgeslagen route.

## 5. Risico's

1. **Opslagkwaliteit (Fase 0) is de voorwaarde.** Zonder gesnapt pad en hoogtes zijn community-routes onbruikbaar om te beoordelen.
2. **Publieke Overpass en Valhalla zijn demo's.** Niet bedoeld voor schaal; plan een eigen instantie vóór echte groei (zie `config/api_usage.php`).
3. **Licenties.** OSM = ODbL met attributie; geen mix met de eigen gebruikersrechten zonder `source`-veld. Mapbox-voorwaarden blijven de beperking voor offline-pakketten.
4. **Moderatie en misbruik** zodra publieke routes vindbaar zijn (illegale/gevaarlijke routes, spam): meld-knop, admin-beheer, snel kunnen verbergen.
5. **Privacy.** Alleen bewust gepubliceerde routes zijn vindbaar; tracks (Activities) pas later, met bbox berekend uit de al getrimde punten.
6. **Veiligheid bij bergroutes.** Geen onterecht "veilig"-gevoel: toon moeilijkheid, bron en een duidelijke disclaimer; alpine routes kunnen levensgevaarlijk zijn.

## 6. Beslissingen die van jou nodig zijn

1. **"Mijn profiel" = je eigen routes in je account** (opslaan als routekaart, daarna optioneel publiceren) of een aparte lijst "Opgeslagen/Favorieten"? Voorstel: opslaan als routekaart, geen aparte lijst.
2. **Cold start:** beginnen met OSM-routes (direct dekking, ODbL-attributie) naast community-routes? Voorstel: ja.
3. **Gratis gebruikers:** mogen die routes uit Ontdekken opslaan? Nu geeft klonen 402 (premium `route_maps`, max 1 kaart). Voor groei moet minstens een beperkt aantal gratis kunnen (bijv. 5 opgeslagen routes). Voorstel: ja, met een limiet.
