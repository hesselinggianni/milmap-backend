# Nationale topokaarten per land (Top25raster-equivalenten)

Onderzoek 2026-07-18 voor uitbreiding van de basemap-keuze in de app, naast
NL (PDOK/Kadaster `top25`) en NO (Kartverket `norge-top25`). De app verwacht
idealiter XYZ-tegels in Web Mercator (EPSG:3857), 256px, gratis, CORS-open —
zie `MilMap-Frontend/src/config/baseLayers.js`.

Endpoints hieronder zijn live geverifieerd (HTTP 200 + content-type + CORS),
tenzij anders vermeld.

## 🇩🇪 Duitsland — direct inbouwbaar

**TopPlusOpen** (BKG — Bundesamt für Kartographie und Geodäsie). Dé officiële
open topo-webkaart, DTK-achtige cartografie, heel Duitsland (+ buurlanden op
kleinere schalen).

- Tegel-URL (LET OP: `{z}/{y}/{x}`):
  `https://sgx.geodatenzentrum.de/wmts_topplus_open/tile/1.0.0/web_scale/default/WEBMERCATOR/{z}/{y}/{x}.png`
  - Variant `web` = standaard webweergave; `web_scale` = schaalvast, oogt het
    meest als een klassieke topografische kaart (beide geverifieerd, 200/PNG).
- CORS: expliciet OK voor app.milmap.nl (geverifieerd).
- Licentie: Datenlizenz Deutschland Namensnennung 2.0 (dl-de/by-2-0).
- Attributie: `© Bundesamt für Kartographie und Geodäsie (jaar)`.
- Zoom: t/m ±z18.

## 🇺🇸 USA — direct inbouwbaar

**USGS Topo** (The National Map) — de moderne US Topo-cartografie (opvolger
van de 1:24.000 quads) als tegeldienst.

- Tegel-URL (LET OP: `{z}/{y}/{x}`, géén extensie):
  `https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}`
- Formaat: JPEG; CORS: `*` (geverifieerd); z16 geverifieerd (≈1:24k-detail).
- Licentie: public domain (US federal).
- Attributie: `USGS The National Map`.

## 🇱🇻 Letland — vrijwel direct inbouwbaar

**LĢIA Topogrāfiskā karte 1:50 000 (Topo50)** — afgeleid van de militaire
1:50k-kaart, heel Letland, open aangeboden via LVM GEO (Latvijas valsts meži).

- WMS: `https://lvmgeoserver.lvm.lv/geoserver/ows` — laag `public:Topo50`
  (GeoServer; ondersteunt EPSG:3857 GetMap → OpenLayers `TileWMS` werkt
  direct; er is ook een GWC WMTS onder `/geoserver/gwc/service/wmts`).
- ⚠️ Endpoint komt uit de officiële LVM GEO-documentatie maar was vanaf onze
  locatie niet bereikbaar (mogelijk geo-blokkering) — eerst testen vanaf de
  productieserver/appdomein vóór inbouwen.
- Ook beschikbaar: Topo10 (1:10k) via LĢIA-diensten.
- Licentie: open data (LĢIA sinds 2021); attributie LĢIA + LVM GEO.

## 🇵🇱 Polen — kan, maar vergt reprojectie

**GUGiK Geoportal — Mapa topograficzna (TOPO)**. Open en gratis (Poolse
geodata sinds 2020 grotendeels open), maar GEEN Web Mercator:

- WMTS: `https://mapy.geoportal.gov.pl/wss/service/WMTS/guest/wmts/TOPO`
  → alleen TileMatrixSets EPSG:2180 en EPSG:4326 (geverifieerd).
- WMS: `https://mapy.geoportal.gov.pl/wss/service/img/guest/TOPO/MapServer/WMSServer`
  → CRS: 2176–2180, 4326, CRS:84 — óók geen 3857 (geverifieerd).
- Inbouw: proj4 + EPSG:2180 registreren en OpenLayers de bron client-side
  laten reprojecteren (OL ondersteunt raster-reprojectie op tile-sources).
  Werkt, maar iets zwaarder/onscherper dan native 3857-tegels.
- Attributie: GUGiK / geoportal.gov.pl.

## 🇱🇹 Litouwen — vergt registratie én reprojectie

**TOP50LKS** (nationaal topografisch 1:50 000, geoportal.lt) bestaat, maar de
view-services erachter geven zonder account een 401 (geverifieerd op
`top50lks`, `gisc_topo50`, `topografinis` onder
`https://www.geoportal.lt/mapproxy/…`). Bestellen/registreren op geoportal.lt
is gratis; daarna krijg je de service-URL.

- Vrij zonder account: nationale basiskaart
  `https://www.geoportal.lt/mapproxy/gisc_pagrindinis/MapServer`
  (GRPK-gebaseerd, geverifieerd) — maar in EPSG:3346 (LKS-94), 512px-tegels
  → zelfde reprojectie-aanpak als Polen.
- Attributie: NŽT / geoportal.lt.

## 🇺🇦 Oekraïne — nu geen officiële optie

De nationale NSDI-geoportal (nsdi.gov.ua, met de staats-topokaart 1:50k) is
sinds 24-02-2022 wegens de staat van beleg afgesloten voor publiek gebruik,
tot einde krijgsrecht (geverifieerd op de portal zelf). Er is dus geen legale
officiële tegel-/WMS-dienst om in te bouwen.

Alternatieven zolang dat zo blijft:
- **OpenTopoMap** (OSM-gebaseerde topo-stijl, wereldwijd, CC-BY-SA) of Esri
  World Topo als generieke topo-laag voor UA;
- commercieel: East View Geospatial verkoopt de (Sovjet-)1:50k/1:100k-rasters
  van Oekraïne; Visicom (Kyiv) levert commerciële kaartdata;
- her-evalueren na opheffing van het krijgsrecht.

## Status: INGEBOUWD (2026-07-19)

DE (`de-topplus`), US (`usa-topo`), PL (`pl-topo`) en LT (`lt-topo`) zitten in
de app als basemap-thema's naast NL/NO — centrale config + bronnen in
`MilMap-Frontend/src/config/baseLayers.js` (COUNTRY_THEMES +
createBaseTileSource), themalijst/watcher in `views/maps/View.vue`. Visueel
geverifieerd via de dev-only checkpagina `/__basemaps` (BasemapDevCheck.vue,
alleen in dev-builds).

Bevindingen bij het inbouwen:
- PL/LT WMTS: alleen KVP werkt (REST-templates geven 500's bij LT); de grove
  tegel-matrices leveren LEGE 200-responses → minZoom vastgezet op het eerste
  niveau met échte data (PL z10, LT z12) — daaronder zou de kaart blanco zijn.
- LT dekt op middenniveaus alleen stedelijk gebied; vanaf z12 landsdekkend.
  Start daarom op Vilnius.
- mapy.geoportal.gov.pl ging na intensief testen tijdelijk dicht voor ons IP
  (alle verbindingen geweigerd) — herstelt vanzelf; iets om te onthouden bij
  eventueel toekomstig serverside proxien/cachen.
- Letland NIET ingebouwd: LVM- én LGIA-endpoints onbereikbaar vanaf twee
  netwerken tijdens de bouw; later opnieuw proberen (TileWMS `public:Topo50`).

## Aanbevolen volgorde van inbouwen (origineel onderzoek)

1. Duitsland (TopPlusOpen `web_scale`) — copy-paste naast `norge-top25`.
2. USA (USGSTopo) — idem, let op jpeg + `{z}/{y}/{x}` + maxZoom 16.
3. Letland (Topo50 TileWMS) — na bereikbaarheidstest vanaf prod.
4. Polen (WMTS 2180 + proj4-reprojectie) — eenmalige reprojectie-plumbing;
   die herbruik je daarna voor Litouwen.
5. Litouwen (registratie TOP50LKS, anders basiskaart + reprojectie).
6. Oekraïne: voorlopig alleen een generieke fallback-laag.

## Uitbreiding 2026-10-29: CH/ES/AT/CZ/LU/EE/FR (naar aanleiding van Topo GPS)

Aanleiding: het kaartaanbod van Topo GPS (topo-gps.com, rdzl) bekeken. Hun kaarten zelf
zijn niet bruikbaar (in-app aankoop, voorwaarden: "may not be distributed in any way,
except when the map license explicitly allows it"), maar ze noemen per land de nationale
bron. Die zijn direct ingebouwd als basemap-thema's (zelfde patroon als NO/DE):

| Thema | Dienst | Licentie / attributie | Tegel-URL-valkuil |
|---|---|---|---|
| `ch-topo` | swisstopo `pixelkarte-farbe`, 3857 | OGD, gratis ook commercieel; "© swisstopo" | `{z}/{x}/{y}.jpeg`; 400 buiten dekking |
| `es-topo` | IGN España `mapa-raster` laag `MTN` | CC BY 4.0; IGN noemen | WMTS-KVP, `GoogleMapsCompatible` |
| `at-basemap` | basemap.at `geolandbasemap` | CC BY 4.0; "Datenquelle: basemap.at" | `{z}/{y}/{x}.png`; 404 buiten Oostenrijk (geknipt op landsgrens) |
| `cz-topo` | ČÚZK `ZTM_WM` (ArcGIS-cache) | CC BY 4.0; © ČÚZK | `{z}/{y}/{x}` zonder extensie; 404 onder z6 |
| `lu-topo` | Geoportail.lu opendata `topo_20k` | CC0 | laag `topomap` geeft 400; `topo_20k`/`topo_50k` werken |
| `ee-topo` | Maa-amet `kaart@GMC` (TMS) | open data; bron + jaar noemen | TMS → `{-y}`; 404 boven z18 |
| `fr-plan` | IGN Géoplateforme `PLANIGNV2` | Licence Ouverte 2.0; © IGN | KVP-WMTS, `TILEMATRIXSET=PM`; géén SCAN25 |

Alle zeven: CORS `*`, 256px, geen sleutel nodig. Visueel gecheckt via `/__basemaps`
(ES/CZ/CH/FR op z14 met echt topo-detail). Licenties zijn uit secundaire bronnen
bevestigd (catalogi/wiki/presentaties), niet uit de officiële voorwaardenpagina's van
elke dienst — vóór commerciële promotie per land nalopen.

**Bewust niet:** BE (NGI CartoWeb `cartoweb.wmts.ngi.be` werkt technisch, maar de
gebruiksvoorwaarden zijn niet open — intern gebruik/commercieel beperkt). Mogelijk wel via
regionale open data (Vlaanderen GRB, Wallonië) — nog niet onderzocht.
**Vergen een gratis sleutel/registratie (niet gedaan):** FI (MML `maastokartta`, 401),
DK (Dataforsyningen token), SE (Lantmäteriet), NZ (LINZ). **Niet gratis:** FR SCAN25.
Niet gedaan omdat ver van de doelgroep: JP (GSI, werkt zonder sleutel), AU.
Geen bulk-offline voor deze zeven (BULK_DOWNLOAD_COUNTRY_THEMES ongewijzigd, geen
regio-opsplitsing in countryRegions.js).

## België — onderzoek 2026-10-29 (geen thema gebouwd)

Vier niveaus, elk met eigen voorwaarden. Geverifieerd uit de capabilities/voorwaardenpagina van de dienst zelf, tenzij anders vermeld.

- **Federaal — NGI CartoWeb.be** (`https://cartoweb.wmts.ngi.be/1.0.0/topo/default/3857/{z}/{y}/{x}.png`, lagen `topo`, `topo_grey`, `overlay`; z8–16 echte tegels, daarbuiten 404).
  Technisch bruikbaar, juridisch **niet gratis voor MilMap**: gratis alleen voor niet-commercieel gebruik (CC-BY-NC). Commercieel
  gebruik vereist registratie + jaarvergoeding (tabel op [ngi.be](https://ngi.be/gebruiksvoorwaarden-cartoweb-be/): klein <50 pers./<€10 mln omzet = €60/jaar,
  medium €240, middelgroot €960, groot €3.840, super €15.360) — **maar** wie CartoWeb.be "als basis wil gebruiken, informatie wil toevoegen en
  als product aan klanten wil aanbieden" betaalt royalty's volgens een maatwerkovereenkomst (zo zou MilMap kunnen vallen; NGI beslist).
  Verder: geen automatisch downloaden (botst met offline-cache/bulk), NGI-copyright verplicht (+ TomTom bij 1:2.500–1:10.000, EuroGeographics bij crossborder), niet groter dan A3 afdrukken.
- **Vlaanderen — GRB basiskaart** (`https://geo.api.vlaanderen.be/GRB/wmts`, lagen `grb_bsk`, `grb_bsk_grijs`, TileMatrixSet `GoogleMapsVL` = 3857, 256px, KVP, z0–21):
  capabilities zeggen "Het gebruik van de service is kosteloos"; licentie Gratis open data licentie Vlaanderen v1.02 (uit zoekresultaten, primaire tekst niet gelezen),
  bronvermelding "Bron: Grootschalig Referentie Bestand Vlaanderen, Digitaal Vlaanderen". Tegels 200 + CORS `*` van z6 t/m z20.
  ⚠️ Het is een **kadaster-/basiskaart** (gele grond, rode gebouwen, perceelgrenzen), géén topo — geen hoogtelijnen/bos.
  Let op: `…/GRB-basiskaart/wmts` geeft een error.html; het juiste pad is `…/GRB/wmts`, en zonder browser-UA wordt soms geweigerd.
- **Brussel — UrbIS** (`https://geoservices-urbis.irisnet.be/geoserver/gwc/service/wmts`, lagen `urbisFR/NL(+Gray)`, TileMatrixSet `EPSG:900913`, matrix-id `EPSG:900913:{z}`):
  capabilities: Fees = **CC0** (publiek domein). Tegels 200 + CORS `*` vanaf z10 (z6/z8 = lege 788-byte tegels → minZoom 10). Straatkaart, geen topo; klein gebied (±[4.24,50.76,4.48,50.92]).
- **Wallonië — SPW**: ArcGIS MapServers op `geoservices.wallonie.be/arcgis/rest/services/` (o.a. `IGN/TOP10s_NB`, `IGN/FOND_IGN_NB`, `FDC/FDC_SPW_LEGER`,
  `DONNEES_BASE/FDC_SPW`, `TOPOGRAPHIE/PICC_VDIFF`). Alle in Lambert (EPSG:31370/3812) met eigen tegelraster (512px, aangepaste origin) — geen 3857, dus proj4 +
  reprojectie nodig (zoals PL/LT), of dynamische WMS. **Licentie niet vastgesteld**: SPW hanteert eigen licenties per dataset (fiche descriptive op het
  Géoportail; mailadres voor vragen: helpdesk.carto@spw.wallonie.be); de achtergrondkaarten bevatten bovendien IGN/AGDP-data ("(c) AGDP, IGN, SPW"), wat
  NGI-rechten kan raken. Niet gebouwd; eerst bij SPW navragen.

**Status 2026-10-29 (later):** Vlaanderen en Brussel zijn ingebouwd als `vl-grb` (GRB `grb_bsk`, z7–20) en `bx-urbis` (UrbIS `urbisFR`, z10–20) —
zelfde plekken als de andere landen (baseLayers.js, View.vue-themalijst, shareThemes.js, MapShareController::BASE_THEMES + tests). Visueel gecheckt via `/__basemaps`
(Gent z14, Brussel z16); backend-test `MapShareBaseThemeTest` 6/6 groen.
