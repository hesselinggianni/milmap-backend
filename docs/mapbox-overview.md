# Lokale Mapbox-overzichtskaart

Vier stijlen: Outdoors, Streets, Satellite en Satellite + Streets. Light en Dark zijn uitgefaseerd; bestaande keuzes vallen terug op Outdoors.

Kaart en wereldbol delen 512×512 WebP-tegels, kwaliteit 90, zoom 0 t/m 6. Per stijl 5.461 tegels, totaal 21.844 per taal. Zoom 0–1 voor de wereldbol voegt slechts 20 tegels toe over alle vier stijlen.

## Opslagschatting (18 oktober 2026)

Gebaseerd op 1.108 opgehaalde proeftegels: alle tegels op zoom 0–3 en een vaste 8×8-steekproef per stijl op zoom 4–6. Nederlandse labels. Dit is een schatting van de WebP-bestandsgrootte, exclusief bestandssysteemoverhead.

- outdoors-v12: 0.070 GB
- streets-v11: 0.052 GB
- satellite-v9: 0.095 GB
- satellite-streets-v11: 0.110 GB

Totaal: 0.327 GB. Reserveer circa 0,5 GB schijfruimte. Andere talen kunnen extra kopieën toevoegen.

Zoom 0–6 is volledig gedownload en actief op de MilMap-server (18 oktober 2026). De uitbreiding naar zoom 7–10 is alleen gemeten en bewust niet doorgevoerd (zie besluit 18 oktober).

## Gebruik

Vereist: MAPBOX_TOKEN in backend .env en Python met Pillow/WebP. Desgewenst MAPBOX_PYTHON_BIN instellen.

```sh
php artisan tiles:warm-mapbox-overview --dry-run
php artisan tiles:warm-mapbox-overview --estimate
php artisan tiles:warm-mapbox-overview
```

De downloader hervat en slaat verse bestaande tegels over. Bestanden staan in storage/app/tiles/mapbox-overview/{style}/{language}/{z}/{x}/{y}.webp. De publieke route /api/v1/tiles/mapbox-overview/{style}/{language}/{z}/{x}/{y} serveert deze bestanden en haalt ontbrekende of meer dan 30 dagen oude tegels op aanvraag opnieuw op. Zoom >6 blijft rechtstreeks bij Mapbox. De standaard downloader plant geen automatische volledige downloads in.

## Uitbreiding naar wereldwijde zoom 7–10

Extra meting met 128 geografisch gespreide proeftegels per stijl per zoomniveau 7–10 (2.048 nieuwe tegels). Samen met de eerdere meting: 3.156 proeftegels. WebP-kwaliteit 90, Nederlandse labels. De hoogste zoomniveaus gebruiken een vaste willekeurige positie binnen elk vak van een 16×8-wereldraster, gelijk voor alle stijlen.

| Stijl | Geschat totaal zoom 0–10 |
|---|---:|
| outdoors-v12 | 10.50 GB |
| streets-v11 | 7.81 GB |
| satellite-v9 | 24.12 GB |
| satellite-streets-v11 | 25.14 GB |
| Totaal | 67.57 GB |

Dit zijn geschatte WebP-bestandsbytes, exclusief bestandssysteemoverhead; reserveer circa 100 GB voor schattingsmarge en miljoenen losse bestanden. Totaal 5.592.404 tegels (1.398.101 per stijl). De volledige download is nog niet uitgevoerd. De app serveert op dit moment nog steeds alleen zoom 0–6 uit de lokale cache; deze uitbreiding is uitsluitend gemeten.

```sh
php artisan tiles:warm-mapbox-overview --estimate --max-zoom=10
```

## Live installatie zoom 0–6 (18 oktober 2026)

- Alle 21.844 afbeeldingen zijn lokaal volledig gedecodeerd en op 512×512 pixels gecontroleerd.
- Alle controlesommen van de serverkopie komen overeen met de lokale bestanden.
- Werkelijke WebP-bestandsgrootte: 314.953.662 bytes (315 MB / 0,315 GB).
- Werkelijk schijfgebruik van de tegelbestanden op de server: 370.069.504 bytes (370 MB).
- De publieke HTTPS-route is getest voor alle vier stijlen op zoom 0, 1, 2 en 6; afbeeldingsbytes, MIME-type en CORS kloppen. Zoom 7 geeft hier 404.
- De reeds bijgewerkte live frontend (app.6255958f.js, kaartmodule 8761.f917bf9a.js) bevat de lokale omleiding voor 2D en MapLibre/wereldbol. De installatie heeft deze nieuwere frontend behouden en alleen de backendroute/controller bijgewerkt.
- Backup van de servercode: /home/milmap/milmap-backups/mapbox-overview-20261018_060421.

Controle opnieuw uitvoeren:

```sh
python3 scripts/verify-mapbox-overview.py storage/app/tiles/mapbox-overview --decode
```

Het manifest met aantallen, bestandsgrootte en controlesommen per stijl staat in storage/app/tiles/mapbox-overview/manifest.json. `scripts/install-mapbox-overview.py STAGE --backend-only` installeert alleen de backend wanneer de live app de omleiding al bevat. De standaardinstallatie gebruikt een gecontroleerde frontend-stage en stopt als de live bestanden ondertussen zijn veranderd.

## Besluit 18 oktober 2026: server alleen zoom 0–6

De eigen server bewaart alleen zoom 0–6 (21.844 tegels per taal, circa 0,37 GB). Alles vanaf zoom 7 en alle andere talen lopen rechtstreeks via de Mapbox-CDN.

- De download van zoom 7–9 (`tiles:warm-mapbox-packed`, SQLite-bundels) is gestopt na circa 150.000 van 1.376.256 tegels (outdoors-v12) en volledig opgeruimd op de server: `overview-7-9.sqlite` (1,1 GB), status-, pid- en lockbestand. De command en het downloadscript zijn uit de code gehaald.
- `MapboxOverviewTileController::MAX_ZOOM` en `MAPBOX_OVERVIEW_MAX_ZOOM` (frontend) staan op 6; de route geeft 404 boven zoom 6 en `tiles:warm-mapbox-overview` weigert `--max-zoom` > 6.
- Ontbrekende of meer dan 30 dagen oude tegels op zoom 0–6 worden nog steeds op aanvraag opnieuw opgehaald.
- Zoom 2–10 meeleveren in de app is afgewezen: alleen outdoors-v12 zou 10,5 GB zijn (alle stijlen ~67 GB), en Mapbox-voorwaarden kennen geen herdistributie van tegels.
- Gemeten (18 oktober 2026, vanaf Nederland): een tegel uit de eigen cache via PHP/Laravel kost ~85–110 ms tot de eerste byte, rechtstreeks van Mapbox ~20–40 ms. Lokaal is dus alleen sneller als nginx de bestanden direct uitlevert.
