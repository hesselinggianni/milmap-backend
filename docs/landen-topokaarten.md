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
