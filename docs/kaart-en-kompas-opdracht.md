# Kaart-en-kompasopdracht (route-opdracht) — plan

Status: v1 gebouwd, **niet gedeployed** (2026-10-17).

## Doel

Een instructeur plant een kaart-en-kompasopdracht: uit een set waypoints op de
kaart worden automatisch routes gemaakt (bijv. 4 posten, 4–8 km), elke route
wordt aan een deelnemer gekoppeld, en er komt één overzicht met afstand, tijd
per route (dag/nacht), starttijden en "iedereen binnen vóór".

## Aanpassing 2026-10-17 (na feedback)

- Routecode = **cijfer + letter** (1A, 2B, 3C …):
  - **Cijfer = koersloop**: hemelsbreed lopen. Per been moeten **koers in graden** en
    **afstand in meters** zichtbaar zijn (overzicht + routekaarttabel).
  - **Letter = afstandsloop**: route **over de paden** (OSRM-voetroute), niet hemelsbreed.
- Eén waypoint wordt **start/finish** ingesteld: iedereen moet daar binnen de aangegeven tijd/km zijn.
- Optie **"langste route eerst starten"** (aan/uit).
- Routes uit een opdracht staan standaard uit op de kaart (alleen waypoints zichtbaar).

## Definitief model (2026-10-17)

- Er zijn **twee soorten routes** in een opdracht:
  - **Koersroutes (cijfers 1, 2, 3 …)**: hemelsbreed, per been koers (graden) + afstand (m).
  - **Padenroutes (letters A, B, C …)**: over de paden (OSRM-voetroute).
- Per soort stel je apart in: **aantal waypoints (min/max)** en **km-band** (standaard 4–8 km).
- Een deelnemer krijgt een **combinatie**: `1A` = eerst koersroute 1, daarna padenroute A;
  `A1` = eerst padenroute A, daarna koersroute 1. De volgorde is per deelnemer (of
  per groep) in te stellen, zodat sommigen eerst kaart/koers en anderen eerst paden lopen.
- Tijd per deelnemer = tijd koersdeel + tijd padendeel (elk afstand × tempo, dag/nacht).
- Eén **start/finish-waypoint** voor alle routes; "langste combinatie eerst starten" is een optie.

## Aantal deelnemers en combinaties

- Vooraf geef je het **aantal deelnemers** op; daaruit volgt hoeveel koersroutes
  (cijfers) en padenroutes (letters) er gegenereerd moeten worden.
- Combinaties worden met een **letter-offset** gekoppeld zodat niet iedereen dezelfde
  paar krijgt: 1B / 2C / 3D … (of omgekeerd B1 / C2 / D3 …). Offset instelbaar
  (0 = 1A/2B/3C, 1 = 1B/2C/3D). Bij meer deelnemers dan routes lopen de
  offsets door (cyclisch), zodat elke combinatie uniek blijft zolang dat kan.
- Met N deelnemers en K koers- + P padenroutes: er zijn K×P unieke combinaties;
  de app waarschuwt als N daarboven uitkomt (dan lopen combinaties dubbel) en
  toont hoeveel routes er minimaal nodig zijn.
- Het overzicht toont de combinatie per deelnemer, beide delen met afstand en tijd.

## Prikkers (controle per post)

- Op elke post (waypoint) plaatst de instructeur een **prikker**: een tang met een
  patroon op een **3×3-raster (9 punten, zoals een dobbelsteen)**. De deelnemer prikt
  het patroon in zijn kaart/kaartje als bewijs dat hij er was.
- Per waypoint is een **prikpatroon** instelbaar: een selectie van de 9 punten
  (bijv. alleen de hoeken, een kruis, een L). Ontwerpen verschillen dus per post.
- Opties: automatisch unieke patronen toewijzen aan alle posten van de pool
  (voorkomt dubbele patronen), handmatig aanpassen, en een waarschuwing bij
  dubbele patronen.
- **Zichtbaar bij het waypoint** als optie: het patroon als klein 3×3-icoon in de
  waypoint-details, op het kaartlabel (aan/uit) en in het overzicht per route
  (post nr 3 → patroon). Zo weet de instructeur welke prikker bij welke post hoort.
- Opslag: veld `prikker` (array van 9 booleans / indices 0–8) op het waypoint;
  mee in de routes (checkpoint) zodat tabel en print het kunnen tonen.
- Ook in de export (PDF/CSV) en een printbare **prikkerlijst** voor de instructeur.

## Lopen met papier of met de app

- Per opdracht kies je de uitvoering: **papier** (PDF/print per deelnemer: route, koersen,
  afstanden, starttijd, prikkerraster) of **app** (deelnemer wordt uitgenodigd), of beide.
- **App-deelnemer:** de instructeur nodigt deelnemers uit (bestaand uitnodigingssysteem,
  koppeling deelnemer-naam ↔ account). De deelnemer ziet alleen **zijn eigen** route/combinatie,
  starttijd en uiterlijk-binnen — niet die van anderen.
- **Beperkingen door de instructeur** (per opdracht, eventueel per deelnemer), als
  aan/uit-schakelaars; uit = functie verborgen/geblokkeerd tijdens het actieve tijdvenster
  (start – uiterlijk binnen):
  - navigatie / route volgen, koers- en afstandstools (kompas, meetlat, MGRS-zoeken),
  - locatie delen,
  - alle punten/lagen uit de linker zijbalk (waypoints, gebieden, andere routes),
  - routekaart-technieken (tabel, presentatie, flyover, hoogteprofiel),
  - aanmaken/bewerken van eigen waypoints en routes.
  Presets: "Examen" (alles uit), "Training" (alles aan), eigen keuze.
- **Handhaving:** server-side waar het kan (API's geven tijdens het venster geen andere
  routes/waypoints terug en weigeren locatie delen); de rest in de app (UI verborgen).
  Eerlijke beperking: een app-restrictie is geen waterdichte examenbewaking — een deelnemer
  kan een andere kaartapp of papieren kaart gebruiken. Voor een examen blijft toezicht nodig.
- Instructeur kan het venster direct beëindigen of verlengen (nood), en ziet wie de
  uitnodiging heeft geaccepteerd.

## Beoordeling en score

- Per deelnemer legt de instructeur na afloop vast:
  - **Per post: goed geprikt of niet** (patroon komt overeen met de prikker van die post),
    eventueel met deelpunten (bijv. patroon deels goed).
  - **Op tijd binnen of niet**: invoer van de binnenkomsttijd; de app bepaalt zelf of dat
    vóór "uiterlijk binnen" is en hoeveel minuten te laat/over was (buffer).
- **Puntentelling instelbaar per opdracht:** punten per goed geprikte post, strafpunten
  (of nul punten) bij te laat, optioneel bonus voor ruim op tijd, en een slaag-grens
  (bijv. minimaal 70 %) voor Examen. Training toont dezelfde score maar zonder geslaagd/gezakt.
- **Totaalscore** per deelnemer = punten posten + tijdpunten; getoond in het overzicht
  (kolommen: goed geprikt x/y, op tijd ja/nee, score, resultaat) en in de PDF/CSV.
- Bij papier-uitvoering voert de instructeur dit handmatig in; bij app-uitvoering kan de
  binnenkomsttijd later automatisch uit het afsluiten van de route komen.
- Opslag: `results` per deelnemer in de opdracht (post-uitkomsten, binnentijd, score).

## Begrippen

| Begrip | Betekenis |
|---|---|
| Opdracht | Bundelt routes + deelnemers + planning. Type: **Training** of **Examen**. |
| Pool | De waypoints die je selecteert; routes worden alleen hieruit gebouwd. |
| Route | Gewone routekaart (`route_maps`) met code **1a, 2b, 3c …** |
| Post-nummer | Nummer van een waypoint in de pool (nr 1, nr 2 …); natuurlijk gesorteerd op label. |
| Start/finish | Optioneel waypoint waar elke route begint en eindigt ("binnen zijn"). |
| Tempo | Tijd per km, **4 waarden**: training-dag, training-nacht, examen-dag, examen-nacht. |
| Toegestane tijd | afstand × tempo (per type en dag/nacht). |
| Venster | Maximale tijd die iedereen krijgt (bijv. 4:00). |
| Uiterlijk binnen | Starttijd van de **laatste** starter + venster → één tijd voor iedereen (bijv. 23:00). |
| Buffer | Uiterlijk binnen − verwachte aankomst. Daarna gaat het noodteam zoeken. |

## Rekenregels

- Afstand = som van de hemelsbrede benen (kaart en kompas = rechte koerslijnen),
  incl. via-punten als een route later handmatig is aangepast.
- Toegestane tijd = afstand_km × tempo[type][dag|nacht] (seconden per km).
- Eerste start = opdrachttijd + briefingduur.
- Startslot = ⌊index / groepsgrootte⌋; start = eerste start + slot × interval.
  (Groepsgrootte 1 = om de X minuten één naam; 20 = twintig namen tegelijk.)
- Uiterlijk binnen = laatste start + venster. Zo heeft iedereen minstens het
  volledige venster gehad, ook de laatste starter met de langste route.
- Waarschuwing als het venster korter is dan de langste toegestane tijd.
- Optionele startvolgorde "langste route eerst" — de laatste starters hebben dan
  de kortste routes en iedereen komt rond dezelfde tijd binnen.
- Dag- en nachtloop hebben elk een eigen planning (starttijd, briefing,
  interval, groepsgrootte, venster); het overzicht wisselt met één schakelaar.

## Generator (in de app, seeded → "opnieuw genereren" geeft andere routes)

1. Kies per route k posten (min..max) uit de pool — afwisselend willekeurig en
   "lokaal" (buren van een startpost), zodat er ook bij een grote pool genoeg
   kandidaten binnen 4–8 km vallen.
2. Volgorde: alle permutaties (k ≤ 6, anders nearest-neighbour + 2-opt); kies de
   kortste volgorde die binnen de km-grens valt (natuurlijke lus, geen zigzag).
3. Score per kandidaat (laagste wint), zodat deelnemers elkaar niet volgen:
   zelfde eerste post (zwaar), zelfde laatste post, overlap met eerdere routes
   (kwadratisch), zelfde benen in dezelfde richting, gelijkmatig postgebruik,
   lichte voorkeur voor het midden van de km-band.
4. Lukt een route niet binnen de grenzen → melding met advies (meer waypoints,
   ruimere km-band, minder posten).

## Opslag (backend)

- Nieuwe tabel `route_assignments`: id, map_id, owner_id, title, type,
  settings (json: tempo, generator, planning dag/nacht), participants (json:
  `[{id, name, route_code}]`).
- `route_maps` + `route_assignment_id` (FK, nullOnDelete) + `route_code`.
  Bewust NIET in de gewone update-whitelist; alleen de opdracht zet ze.
- Endpoints (auth, toegang = MapPolicy view/update van de kaart):
  - `GET  /api/v1/maps/{mapId}/route-assignments`
  - `POST /api/v1/maps/{mapId}/route-assignments` — opdracht + routes in één transactie
  - `GET  /api/v1/route-assignments/{id}`
  - `PUT  /api/v1/route-assignments/{id}` — zonder `routes`: alleen instellingen;
    mét `routes`: oude routes loskoppelen + naar prullenbak, nieuwe aanmaken
  - `DELETE /api/v1/route-assignments/{id}?with_routes=1` — anders blijven de
    routes als losse routekaarten bestaan
- Routes blijven gewone routekaarten → routekaarttabel, print, presentatie,
  flyover, navigeren en Garmin werken er direct op. Posten zijn checkpoints
  met het post-nummer als label; snelheid = 60 / tempo (km/u) zodat de
  routekaarttabel dezelfde tijden toont.

## App (frontend)

- **Routekaarten-lijst** (zijbalk + mobiele laag-sheet): knop
  "Kaart-en-kompasopdracht", per opdracht een kaart met routes en
  oog-schakelaars, "alles tonen/verbergen" en "Overzicht".
- **Zichtbaarheid per route** (oog-icoon, per kaart lokaal bewaard): meerdere
  routes tegelijk aan = alle lijnen + posten tegelijk zichtbaar. Opdracht-routes
  staan standaard uit → je ziet alleen de waypoints. Losse routekaarten staan
  standaard aan (bestaand gedrag). Een route selecteren zet 'm aan.
- **Dialoog (90%)**: links instellingen (opdracht, waypoints-pool met mini-kaart,
  deelnemers, planning, tempo), rechts het overzicht:
  `Naam | Route | Afstand | Posten (nr 3 · 7 · 12 · 5) | Tijd dag | Tijd nacht |
  Type | Start | Verwacht binnen | Buffer`, met kop "Iedereen binnen vóór 23:00".
  Smal scherm: tabbladen Instellingen / Overzicht.
- Export: PDF (A4 liggend) en CSV.

## Bewust (nog) niet in v1

- Lokale (offline-only) kaarten: knop verborgen — opdracht vereist de server.
- Routes langs paden (OSRM): kaart en kompas is hemelsbreed.
- Automatisch noodteam-alarm na "uiterlijk binnen" (live tracking koppelen).
- Koppelen van deelnemers aan app-accounts / live posities.
- Pre-deploy: migratie `2026_10_17_150000_create_route_assignments_table` op prod draaien.
