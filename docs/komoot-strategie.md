# Strategie: Komoot verslaan met MilMap (opgesteld 2026-10-29)

## 0. Kernconclusie

Komoot frontaal verslaan op "route plannen voor wandelaars en fietsers" is niet realistisch. Ze claimen 45 miljoen gebruikers, 8M+ routes en een community die elke week groeit. Maar Komoot is kwetsbaar op vijf punten waar MilMap structureel sterker kan zijn:

1. **Groepen en teams.** Komoot is een solo-product (alleen Premium "Live Tracking" richting vrienden/familie).
2. **Echte topografische kaarten en coördinaatsystemen** (nationale topokaarten, MGRS). Komoot noemt dat nergens.
3. **Vertrouwen en prijs.** Betaalmuur-ophef, upsell-druk, personeel grotendeels ontslagen na overname.
4. **Privacy.** MilMap heeft zero-knowledge E2EE chat; Komoot heeft dat niet.
5. **Snelheid van innovatie.** Een gekrompen team (volgens BikeRadar tot ~85% ontslagen) kan geen breed front bedienen.

"Winnen" definiëren we daarom als: (a) het beste alternatief worden voor Komoot-gebruikers die weg willen, (b) de standaard worden voor groepen/organisaties die samen op pad gaan, (c) pas daarna breder naar solo-outdoor.

## 1. Wat we over Komoot weten (bron + datum)

| Feit | Bron / datum |
|---|---|
| Routes plannen gratis. Offline kaarten, turn-by-turn, sport-specifieke kaarten, 3D, meerdaagse planner, Live Tracking, weer, persoonlijke Collecties = Premium | komoot.com/nl-nl, gelezen 2026-10-29 |
| Garmin- en Wahoo-integratie, standalone Apple Watch-app; Watch kreeg echte offline routing in jan 2026 | komoot.com/nl-nl/features; DC Rainmaker, jan 2026 |
| Per 27 feb 2025 moesten nieuwe gebruikers Premium hebben om routes naar Garmin/Wahoo/etc. te syncen ($4.99/mnd of $59/jaar). Regio-ontgrendeling €8,99; wereld €19,99–29 eenmalig | DC Rainmaker, 19 mrt 2025 |
| Sept 2025: Premium €59,99/jaar, €6,99/mnd (web), €4,99/week (mobiel). Komoot zegt dat route-sync naar apparaat voor meer gebruikers, ook gratis, beschikbaar is | BikeRadar, 15 sep 2025 |
| Overname door Bending Spoons (maart 2025); tot 85% van 150 medewerkers ontslagen, oprichters weg | BikeRadar, Emerce |
| Klachten: sync-betaalmuur, upsell-prompts, batterijverbruik, offline-kaarten die crashen (anekdotisch: Trustpilot/forums) | Trustpilot, forums; richtinggevend, geen meting |
| Roadmap volgens Komoot: heatmaps, badges, meer waypoint-functies, donkere modus | BikeRadar, sept 2025 |

**Niet geverifieerd:** de actuele prijzen van vandaag (de pricing-pagina gaf 404, de koop-pagina laadt client-side). Controleer dit in de app vóór je het in marketing gebruikt. Prijsvergelijkingen op de site pas publiceren na handmatige check.

## 2. Waar MilMap al staat (geverifieerd in code en eigen docs)

- Eigen GPX-import/-export (`gpxParser.js`, `ImportGpxDialog.vue`), Garmin Connect-koppeling (routes naar apparaat, activiteiten importeren).
- Routing via OSRM/Valhalla (frontend: RoutePlannerPanel, NavigationView; backend: RouteGenerationService). **Open vraag:** zelf gehost of publieke instantie? Bepaalt kwaliteit en kosten.
- Turn-by-turn NavigationView, track opnemen en delen, flyover/3D-presentatie, weer-widget met weekoverzicht, Apple Watch-app, widgets, Siri-opdrachten.
- Offline snapshots (IndexedDB), tegel-prefetch.
- Nationale topokaarten: NL (PDOK/stafkaart), DE, US, PL, LT, CH, ES, AT, CZ, LU, EE, FR; bergterrein-overlays; MGRS.
- Teams en groepen: missies, O-groep, live samen bewerken, live deelnemerskaart, E2EE chat, team-abonnement met seat-billing (in aanbouw).
- Funnel-bouwstenen: SEO-CMS, 5 sitetalen, steden-/locatie-/MGRS-clusters, partnerportaal (affiliate), launch-coupon, AppSumo-codes, 12 app-talen.

**Structurele zwakte (kritiek):** de basiskaart is Mapbox. Eigen docs (`mapbox-overview.md`, besluit 18 okt 2026): "Mapbox-voorwaarden kennen geen herdistributie van tegels". Dat beperkt (a) gratis offline kaarten voor iedereen, (b) een B2B-kaartleverancier-propositie, en (c) de kostenmarge bij groei. Komoot rendert op OpenStreetMap-data en heeft dit probleem niet.

## 3. Strategie in vijf pijlers

### Pijler 1 — Pariteit op de must-haves (niet slechter dan Komoot)
Controleer en dicht per punt. Kolom 3 is wat ik niet in de code heb kunnen vaststellen.

| Komoot-feature | MilMap | Actie |
|---|---|---|
| Sportprofielen (wandel/fiets/MTB/race/hardloop) met ondergrond- en hoogteprofiel | routeplanner bestaat | audit: profielen, ondergrond-info, hoogteprofiel |
| Offline kaart + offline routing | offline snapshots; routing offline onbekend | testen: route herberekenen zonder bereik |
| Sync naar Garmin | Garmin Connect aanwezig | Wahoo/Coros/Polar/Suunto via FIT/GPX-export of koppeling |
| Meerdaagse planner | missies/waypoints als basis | meerdaagse "tocht" met etappes en overnachtingen |
| Highlights / POI-laag + community-foto's | publiek profiel + route klonen (deploy pending) | Highlights-laag met foto's, per regio |
| Heatmap | — | eigen gebruikers-heatmap (privacy-opt-in) |
| Live tracking | track delen+volgen (gepland/in aanbouw) | afmaken, ook zonder account te bekijken |
| Collections | — | "Verzamelingen" van routes (publiek delen) |

### Pijler 2 — Onderscheid waar Komoot niet kan of wil volgen
- **Groepstochten:** één route/missie, meerdere deelnemers, live zien waar iedereen is, gezamenlijk bewerken, chat. Verkoop als "Komoot is voor jou, MilMap is voor jullie".
- **Precisie:** nationale topokaarten, MGRS/RD/UTM, kompas- en kaartopdrachten, bergterrein-pakket. Doelgroep: Defensie/reserve, scouting, airsoft, SAR, jagers, outdoorscholen, orienteeren.
- **Onderwijs:** KAKO, Connect voor scholen (400 iPads op één NAT is al uitgezocht).
- **Privacy:** E2EE chat/data als aantoonbaar verschil. Dit is niet te kopiëren door een Bending Spoons-bedrijf dat op advertentie-/upsell-metrics stuurt.

### Pijler 3 — Prijs en vertrouwen (de wedge tegen Komoot)
- Beloof publiekelijk: **GPX-export, apparaat-sync en je eigen routes zitten nooit achter een betaalmuur.**
- Eén duidelijke prijspagina, geen verborgen upsells, opzeggen in één klik.
- Haalbaarheid hangt af van de kosten per gebruiker. Meet eerst: admin → Analytics → API-verbruik (Mapbox, Open-Meteo, routing) per actieve gebruiker. Zie risico 1 hieronder.
- Huidige gratis limiet (1 kaart/1 missie) is voor solo-switchers strenger dan Komoot's gratis planner. Overweeg een aparte "solo-route"-ruimte die gratis blijft (plannen, GPX, 1 offline regio).

### Pijler 4 — Switcher-funnel
1. Pagina's `/komoot-alternatief`, `/vs/komoot` in alle sitetalen (EN default, NL, DE, ES, UK) via het SEO-CMS; eerlijke vergelijking, geen onjuiste claims.
2. "Importeer je routes": bulk-GPX-import (Komoot laat gebruikers hun data exporteren, **verifiëren**), stap-voor-stap handleiding in /docs. Geen scraping van Komoot-pagina's (voorwaarden).
3. Affiliate-programma uitrollen bij bikepacking-/wandel-creators (partnerportaal bestaat), launch-coupon van 50% voor Komoot-switchers.
4. Reviewpagina's/alternatieven-sites (AlternativeTo, HiiKER-achtige lijsten), forums (Singletrack World, UKHillwalking), Reddit — organisch, geen spam.

### Pijler 5 — "Kaartleverancier" (B2B)
Komoot verkoopt via komoot.business en route-embeds aan toerismepartners. MilMap-variant:
- Embedbare kaart/route-widget voor clubs, scholen, evenementen (Oirschot-demo-iframe bestaat al).
- Organisatieplan op team-abonnement (seat-billing): scoutinggroep, vereniging, school, bedrijf.
- Eventroutes met checkpoints en live deelnemers (wandelvierdaagse, oriëntatieloop).
- Pas serieus doorpakken **na** oplossing van de Mapbox-licentie (risico 1).

## 4. Fasering

**Fase A — 0 tot 30 dagen: opleveren wat er al is**
- Alle "deploy pending"-werk live zetten (openbaar profiel/route klonen, offline snapshots OTA, foutlog-fixes, Connect-reset, e.d.). Dit is de goedkoopste winst.
- Audit pariteitstabel (pijler 1) en actielijst.
- Kosten per actieve gebruiker uit API-verbruik halen.
- 5 gesprekken met groepsleiders (scouting, airsoft, outdoorschool, reservisten) om pijler 2 te toetsen.

**Fase B — 1 tot 3 maanden: pariteit + funnel**
- Wahoo/Coros-export, offline routing, meerdaagse tocht, Highlights-laag.
- `/komoot-alternatief` pagina's + importgids + affiliate-campagne.
- Prijspagina herzien met de "nooit betaalmuur voor eigen routes"-belofte (alleen als de kosten het toelaten).

**Fase C — 3 tot 6 maanden: groepen als wig**
- Team-abonnement uit aanbouw halen (seat-billing, rechten per lid).
- Groepstocht-flow: route kiezen → uitnodigen → live volgen → samen evalueren.
- Eerste 3–5 referentie-organisaties, case studies.

**Fase D — 6 tot 12 maanden: eigen kaartstack en B2B**
- Beslissing over loskoppelen van Mapbox voor de standaard-outdoorlaag (OSM-vectortegels als PMTiles op eigen CDN, naast PDOK/nationale topo's). Dit maakt gratis offline kaarten én B2B-embeds mogelijk.
- Embeds en organisatieplan commercieel aanbieden.

## 5. Meetpunten (per kwartaal)

- Positie op "komoot alternatief" / "komoot alternative" in NL, DE, EN.
- Aantal Komoot-importers (GPX-batch-imports per nieuwe gebruiker) en hun 30-daagse retentie.
- Aantal actieve groepen (≥3 leden, ≥1 gedeelde tocht per maand).
- Kosten per actieve gebruiker (kaart + routing) versus gemiddelde omzet per gebruiker.
- Conversie gratis → betaald, uitgesplitst naar solo vs groep.

Stopcriterium: als na fase C minder dan ~10 betalende groepen en solo-importers niet boven de normale retentie komen, schuif de focus volledig naar de niche (pijler 2) en laat de solo-switcherstrategie vallen.

## 6. Risico's

1. **Mapbox-licentie en kosten.** Geen herdistributie van tegels; prefetch verhoogt requests (~2,3×). Zonder besluit hierover geen gratis offline, geen B2B-kaartleverancier. Mitigatie: meten (fase A), besluit over eigen OSM-stack (fase D); tot dan Mapbox-voorwaarden controleren voor offline gebruik.
2. **Routingkwaliteit.** Komoot's kern is sportbewust routeren. OSRM/Valhalla-instantie, profielen en updatefrequentie moeten worden gecontroleerd. Slechte routes doden de solo-funnel.
3. **Netwerkeffect.** Komoot heeft miljoenen routes en foto's. Mitigatie: niet daar vechten; eerst groepen en precisiekaarten, community-inhoud via publieke profielen en regionale gidsroutes (SEO-clusters).
4. **Komoot herstelt.** Bending Spoons kan prijzen verlagen of sync-regels versoepelen (sept 2025 al deels). Hang de positionering daarom niet alleen aan de prijs op.
5. **Capaciteit.** Veel parallelle sessies in dezelfde werkmap; lange lijst van "deploy pending". Prioriteer bewust: pariteit en groepen, niet nog drie nieuwe clusters.
6. **Juridisch/merk.** Vergelijkende reclame: alleen controleerbare claims, met bron en datum. Geen scraping van Komoot-content.

## 7. Besluiten

- **Genomen 2026-10-29:** primaire wig = groepen/organisaties. De solo-switcher-funnel (pijler 4) loopt op laag pitje mee; pariteit (pijler 1) alleen voor zover groepen het nodig hebben.
- **Open:** bereid om de standaard-outdoorlaag van Mapbox los te koppelen (pas fase D, maar bepalend voor de roadmap)?
- **Open:** gratis-filosofie: solo-routes echt gratis of de huidige 1-kaart-limiet houden?

## 8. Groepen-eerst uitwerking

### 8.1 Beachhead (advies: begin waar het product al past)
1. **Dag 0–90, trainings- en oefengroepen:** reservisten, airsoft-teams, SAR-/hondenteams, outdoor- en overlevingsscholen, instructeurs (KAKO). O-groep, radiokanalen, taken, evaluaties en de live-deelnemerskaart zijn al voor dit gebruik gebouwd, dus er is weinig nieuwe producttaal nodig.
2. **Dag 60–180, verenigingen met tochten:** scouting, wandel- en fietsclubs, wandelvierdaagse-organisaties. Vraagt een lichtere, niet-militaire laag (taal en sjablonen).
3. **Parallel, sales-led:** scholen via Connect.

### 8.2 Wat er al staat (uit eigen notities en een snelle codecontrole; live-status per onderdeel nog bevestigen)
- Team-abonnement: 10 seats inbegrepen, €2 per extra seat, rollen member/guest, keuze-flow voor uitgenodigden met eigen abonnement, admin-overzicht. Volgens notitie LIVE sinds 2026-07-18; de Stripe-prijs voor extra seats en een end-to-end test met 2 accounts stonden nog open.
- Missies met O-groep, taken, briefings, risico's, evaluaties, audit-log, radiokanalen en uitnodigingen met rollen (`MissionCollaborator`, `/invite/:token` met preview vóór login).
- Live-deelnemerskaart met "navigeert naar"-info, afstand en richting (alleen Drive-kaarten).
- Samen bewerken met veld-locks en overnamedialoog (gebouwd 2026-10-17; deploy pending volgens notitie).
- E2EE groepschat, 1:1 chat en een zwevende chat op de kaart.
- Publieke deellink met data-selectie per laag (zonder account te bekijken).
- Oefening-modus (join, doelen, score) en een nood-communicatiescherm (`/comms/emergency`, alleen een procedurescherm).

### 8.3 Gaten (hypotheses; eerst toetsen in de gesprekken)
| # | Gat | Waarom het ertoe doet |
|---|---|---|
| G1 | Missie-first met jargon (O-groep). Geen lichte "groepstocht" in <10 min | Burgergroepen haken af voor de eerste route er staat |
| G2 | Deelnemer zonder account of app: route + live kaart + chat via link of QR | Groepsleden installeren niets voor één tocht. Controleer wat ShareView live laat zien |
| G3 | Groepsveiligheid: achterblijvers-melding, afstand tot leider/sweeper, geofence rond de route, SOS naar de groep. Niet gevonden in de code | Dit is wat Komoots 1-op-1 Live Tracking niet doet en wat leiders betalen |
| G4 | Offline voor de hele groep: leider zet gebied + route klaar, deelnemers downloaden met één tik (missie-werkgebied bbox bestaat) | Komoot-klacht over offline; groepen zitten vaak buiten bereik |
| G5 | Live delen werkt alleen op Drive-kaarten, niet op lokale kaarten | Mogelijk een gat voor groepen die lokaal werken |
| G6 | Na afloop: evaluatie → deelbare recap (flyover, track, foto's) → deelnemers worden nieuwe gebruikers | Viral loop; bouwstenen bestaan al |
| G7 | Betaalflow: Stripe-prijs `team_extra_seat` koppelen en team-abonnement end-to-end testen; "meeliften"-ervaring voor leden | Zonder dit geen betrouwbare omzet |
| G8 | Veel gebouwd maar niet gedeployed (field-locks, toegangsfixes, publiek profiel) | Eerst opleveren wat er al ligt |

### 8.4 Backlog (volgorde)
**P0, week 1–2**
1. Prod-status controleren en achterstallige deploys opleveren (G8).
2. Stripe `team_extra_seat` aanmaken en koppelen, daarna 2-account-test van het team-abonnement (G7). Dit is jouw stap.
3. 5 gesprekken met groepsleiders uit de beachhead (script: wat gebruiken ze nu? WhatsApp, Komoot, Garmin, papier? Wat is de pijn bij een groepstocht?).

**P1, week 3–6**
4. Snelle "Groepstocht"-flow + 3 sjablonen zonder jargon (training, scoutingweekend, clubtocht) (G1).
5. Deelnemen via link of QR zonder installatie of registratie, met route, live kaart en chat (G2, G5).
6. Offline-pakket voor de groep met één tik (G4).

**P2, week 7–12**
7. Groepsveiligheid: achterblijvers, geofence, SOS naar de groep (G3).
8. Recap-pagina na afloop, deelbaar (G6).
9. Referentieklanten en 2–3 case studies.

**P3, daarna**
Wahoo/Coros en heatmap alleen als groepen erom vragen; solo-funnel; B2B-embeds.

### 8.5 Succescriteria
- Na 90 dagen: 10 betalende teams met ≥3 actieve leden; ≥3 referentieorganisaties met citaat.
- Mediane tijd van aanmelden tot eerste verstuurde uitnodiging <10 minuten.
- ≥50% van uitgenodigde deelnemers opent de link zonder hulp.
- Seat-gebruik: gemiddeld >6 van 10 inbegrepen seats actief.
- **Stop of pivot:** minder dan 3 betalende teams na 90 dagen → onderzoek of het probleem productwaarde, prijs of bereik is, voordat je nog meer bouwt.

Prijscontrole: team-planprijs staat in de admin-billingmapping (niet in deze notities). Reken met een voorbeeldvereniging van 40 leden: owner + 9 inbegrepen, 30 extra seats × €2 = €60/maand bovenop de planprijs. Toets in de gesprekken of dat acceptabel is.
