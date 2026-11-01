# OSM-landenimport

`milmap_routes.py` exporteert GPX en een hervatbaar manifest voor Nederland,
België, Duitsland, Oostenrijk, Engeland, Spanje en Frankrijk. Buiten Nederland
wordt per regio opgehaald. Engeland gebruikt uitsluitend `GB-ENG` en wordt in
de database met landcode `GB` opgeslagen. Spanje omvat de eilandregio's, Ceuta
en Melilla. Frankrijk omvat 13 Europese en 5 overzeese regio's.

De importer accepteert benoemde, bruikbare OSM-relaties binnen de bestaande
sport- en afstandsfilters. Dit is geen volledige inventaris van alle routes
in een land. Een ontbrekende regiogrens geeft een fout, geen lege export.

## Live wachtrij van 31 oktober 2026

Op de server staat de exporter als `milmap_routes_europe.py`, zodat de lopende
Belgische exporter ongewijzigd kan doorgaan. Start vanuit de backend:

```bash
nohup python3 -u scripts/import-europe-routes.py --countries de at eng es fr \
  > storage/logs/import-europe-20261031.log 2>&1 < /dev/null &
```

De wachtrij wacht op de Belgische export en vervolgscripts. Hij verwerkt daarna
ieder land per regio, voor MTB, wandelen en hardlopen. Iedere regio krijgt een
eigen exportmap, een volledigheidscontrole, een dry-run en een live import.
Er wordt geen `--unpublish-missing` gebruikt. Bestaande relaties worden op
OSM-ID bijgewerkt; er worden geen dubbele database-items aangemaakt.

De runner gebruikt een procesvergrendeling, bewaart afgeronde regio's en probeert
mislukte regio's nog eenmaal. Herstart hetzelfde commando om onafgeronde regio's
te hervatten. De statussen `waiting_for_belgium`, `running`, `failed` en `complete`
staan in `storage/app/imports/europe-20261031/status.json`. Alleen `complete`
betekent dat alle 63 regio's zijn geëxporteerd en verwerkt. Het veld `exported`
is een aantal per regio; grensroutes kunnen in meerdere regio's voorkomen en
worden op database-ID ontdubbeld.

Data: © OpenStreetMap contributors, ODbL 1.0.

De exporter verwerkt standaard 60 relaties per geometrieaanvraag, wacht 0,5
seconde tussen batches en onthoudt tijdens een run welke Overpass-mirror het
snelst antwoordt. Een te zware batch wordt automatisch gehalveerd. Productie
kan dit bijstellen met `MILMAP_ROUTE_BATCH` en `MILMAP_ROUTE_PAUSE`.

## Tweede wachtrij

Zwitserland, Italië, Luxemburg, Portugal, Slovenië, Noorwegen, Tsjechië en
Schotland worden na de eerste Europese wachtrij verwerkt. Deze uitbreiding
bevat ook gewone fietsroutes naast MTB, wandelen en hardlopen. Schotland wordt
net als Engeland met landcode `GB` en een eigen regio opgeslagen.

## Wereldwijde wandelimport — 1 november 2026

`scripts/import-world-walking.py` verwerkt wereldwijd alle `type=route` met
`route=hiking` of `route=foot`, inclusief naamloze routes en knooppuntverbindingen,
zonder afstandsfilter. Hij wacht eerst op de bestaande landenwachtrijen.
Subrelaties worden recursief gelezen; losse trajecten blijven losse GPX-segmenten.
Bestaande records worden bijgewerkt op OSM-ID. Bekende landen/regio's blijven
behouden; een landenindex vult nieuwe landen in (grensroutes krijgen één land).
Onbekend land krijgt `ZZ`. Originele tags staan bij iedere batch in osm-tags.json.
Status en hervatbare batches: `storage/app/imports/world-walking-20261101/`.
Log: `storage/logs/import-world-walking-20261101.log`.
Start/hervat met `python3 -u scripts/import-world-walking.py` vanuit de backend.
Routes zonder geometrie worden apart geregistreerd onder `unusable`.
`complete_with_unusable` betekent dat zulke relaties niet zijn geïmporteerd.

## Volledige Europese fiets-/MTB-import — 1 november 2026

`scripts/import-europe-cycling.py` importeert `type=route` met exact `route=mtb`
of `route=bicycle`, inclusief naamloze routes, knooppuntverbindingen en alle
lengtes. Naamloze relaties krijgen name/ref of een herkenbare OSM-ID-naam.
Geen bestaande naam-, netwerk-, plausibiliteits- of afstandsfilters worden gebruikt.
Subrelaties worden recursief verwerkt met cyclusdetectie; losse wegen blijven
losse GPX-segmenten. Niet-eindige coördinaten, ongeldige breedte/lengte en
nulgeometrie worden afgekeurd. Bron: © OpenStreetMap contributors, ODbL 1.0;
GPX en database bewaren de relatie-URL en bronvermelding.

### Operationele geografische dekking

De expliciete, ruime Europese selectie is de doorsnede van OSM-landgebieden met
het venster zuid=34°, west=-32°, noord=82°, oost=60°. Een relatie wordt geselecteerd
wanneer Overpass die zowel in het landgebied als in dit venster vindt. De volledige
relatiegeometrie wordt vervolgens behouden, ook wanneer die de grens overschrijdt.
Dit is een reproduceerbare ruime dekking, geen exacte fysieke continentgrens:
Cyprus, Turkije en de Zuid-Kaukasus vallen erin; van Rusland en Kazachstan wordt
het westelijke deel tot 60° OL meegenomen (dus ook delen ten oosten van de Oeral).
Overzeese gebieden buiten het venster vallen erbuiten; Azoren en Madeira vallen
erbinnen, Canarische Eilanden erbuiten. Routes die uitsluitend onder een andere
route-tag of als superroute zijn vastgelegd vallen buiten deze opdracht.

ISO-/OSM-selectiecodes:
AL AD AT BY BE BA BG HR CY CZ DK EE FI FR DE GR VA HU IS IE IT XK LV LI LT LU
MT MD MC ME NL MK NO PL PT RO RU SM RS SK SI ES SE CH TR UA GB GE AM AZ KZ
FO GI AX GG JE IM SJ.

Daarmee worden ook de nog ontbrekende landen, microstaten, Wales en Noord-Ierland
(via heel GB) en bovengenoemde afzonderlijk gemapte gebieden geselecteerd.
Kosovo gebruikt XK. Ontbreekt een OSM-area voor een code, dan wordt dit als
`failed.country/CODE` vastgelegd; het telt nooit als een succesvolle lege export.
Grensrelaties worden in de inventaris op relatie-ID ontdubbeld; nieuw land wordt
bepaald door de eerste code in bovenstaande vaste volgorde. Bestaand land en
provincie blijven behouden. Database-upsert gebeurt op `(source=osm, source_id)`;
de bestaande unieke database-index voorkomt dubbele OSM-relaties.

### Wachtrij, hervatten en controle

Start/hervat vanuit de backend met `python3 -u scripts/import-europe-cycling.py`.
Status: `storage/app/imports/europe-cycling-20261101/status.json`.
Log: `storage/logs/import-europe-cycling-20261101.log`.
Deze taak wacht op beide bestaande Europese runners én de wereldwijde wandelrunner.
De wandelrunner wacht niet op deze nieuwe taak: de afhankelijkheid is eenzijdig.
De bestaande runners worden niet aangepast of onderbroken. De nieuwe runner heeft
een eigen exclusieve proceslock en voert dry-run en live-import serieel uit.

Landeninventarissen worden gecachet; batches bewaren de volledige OSM-respons,
manifest, GPX, `unusable.json` en bij fouten `failure.json`. Status bewaart
`completed`, `unusable` en `failed` afzonderlijk. Batchsleutels bevatten alle
relatie-ID's; reeds afgeronde batches worden overgeslagen. Bij gewijzigde
batchindeling kan een relatie opnieuw worden bijgewerkt, maar niet verdubbeld.
Een onderbroken live-batch wordt veilig opnieuw uitgevoerd; tellingen na hervatten
zijn bewerkingstellingen, geen totaal unieke nieuwe routes sinds de eerste start.
`waiting_for_existing_imports` betekent uitsluitend ingepland; `inventory` haalt
kandidaten op; `running` verwerkt batches. `complete` betekent alle inventarissen
en batches verwerkt; `complete_with_unusable` heeft uitgesloten geometrie;
`failed` vereist herstart en onderzoek van de afzonderlijke fouten.

Geverifieerde start op 1 november 2026 om 08:53 CET: PID 3343990, status
`waiting_for_existing_imports`, completed/failed/unusable nog leeg. Beide
bestaande landenrunners (3072682/3072683) en wandelrunner (3343023) waren actief.
Er waren op dat controlemoment nog geen routes door deze nieuwe taak geïmporteerd.
