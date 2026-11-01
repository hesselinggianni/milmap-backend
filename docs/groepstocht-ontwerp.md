# Ontwerp: Groepstocht-flow (2026-10-30)

Onderdeel van [komoot-strategie.md](komoot-strategie.md), backlog P1 (G1, G2, G4, G5). Dit is een ontwerp; er is nog niets gebouwd.

## 0. Doel en uitgangspunten

**Doel:** een groepsleider gaat in minder dan 10 minuten (met een bestaande route: ~3 minuten) van niets naar "route staat klaar en de groep is uitgenodigd". Deelnemers doen mee via een link of QR, zonder verplichte registratie of installatie.

**Uitgangspunten**
1. **Geen parallel systeem.** Een groepstocht is een missie met een lichte voorinstelling en een eigen, jargonvrije ingang. Missie-aanmaak, rollen, uitnodigingen, live-kaart en chat blijven hergebruikt.
2. **De leider betaalt, de deelnemers niet.** Deelnemers verbruiken geen team-seat en hebben geen eigen abonnement nodig om mee te doen.
3. **Eerlijk over wat het web niet kan.** Een webpagina op iOS rapporteert geen locatie meer als het scherm op slot gaat. Daarom drie deelnamelagen (§3).
4. **Privacy eerst.** Posities zijn voor de leiding, tenzij de leider "iedereen ziet iedereen" aanzet. Alles stopt en wordt opgeruimd bij het einde van de tocht.

## 1. Wat we hergebruiken en wat ontbreekt

Bevindingen uit een code-onderzoek op 2026-10-30 (paden relatief aan `MilMap-Frontend/src`, `milmap-backend`).

**Hergebruik**
| Onderdeel | Waar | Gebruik in de tocht |
|---|---|---|
| Missie aanmaken (één dialoog, alleen `name` verplicht) | `components/missions/CreateMissionSheet.vue` | basis van de wizard |
| Drive-kaart maken + koppelen in één stap | `components/missions/LinkMapDialog.vue` (`createAndLink`) | tocht maakt altijd een servermatige kaart |
| Werkgebied (bbox) | `MissionAreaPicker.vue`, `mission.area`, `applyMissionArea` | gebied uit de route + marge |
| Uitnodigingen met token, preview vóór login | `InvitationService`, `Invitation`, `InviteAccept.vue`, `GET /invitations/token/{token}` | patroon voor de openbare landingspagina |
| Live-kaart met afstand/richting | `components/missions/MissionLiveMap.vue` | leidersscherm |
| Live delen (client-lus, throttle, auto-stop) | `stores/locationSharingStore.js`, `LocationSharingService.js` | aanpassen voor gast-token |
| Publieke, alleen-lezen kaartlink met 5s-polling | `MapShareController::showByToken`, `views/maps/ShareView.vue` | patroon voor de gast-API |
| E2EE-groepschat | `MissionCommsController`, `MissionCommsPanel.vue` | alleen voor app-deelnemers |
| Offline downloader met schatting | `mixins/offlineMapsMixin.js` (`startOfflineDownload`) | bereik "tochtgebied" toevoegen |
| Team-abonnement en seats | `TeamController`, `config/teams.php` | leider-abonnement |

**Ontbreekt of wringt**
1. Join via link/QR zonder e-mailadres: uitnodigingstokens zijn aan een e-mail of gebruiker gebonden (`Invitation::isAddressedTo`).
2. Toegang zonder account: `/missions/:id` is `requiresAuth`; de publieke deellink toont alleen de positie van de maker, geen deelnemers, geen chat.
3. Een missiegebied met één tik offline zetten: `startOfflineDownload` leest `mapArea`, niet `mission.area`, en het missiescherm heeft geen offline-knop.
4. Een deel-knop op het missiescherm: starten kan nu alleen op het kaartscherm (`PresenceSheet.vue`).
5. Live-locatie vereist een Drive-kaart (server-UUID); lokale kaarten vallen af.
6. Geen missietype voor een tocht; alleen O-groep (SMEAC) of een eigen sjabloon.
7. Drempels voor deelnemers: `live_location` is premium (daarna 402 na de 7 proefdagen), de gratis limiet is 1 missie.
8. Delen stopt alleen handmatig of wanneer niemand meekijkt; er is geen einde gekoppeld aan de missiestatus.

**Bug gevonden onderweg (in code bevestigd, niet uitgevoerd):** `app/Http/Middleware/EnsureNotViewOnly.php:71` staat het delen van de eigen positie toe via `#^maps/\d+/user-locations#`. Kaart-id's zijn UUID's (`Map` gebruikt `HasUuids`), dus dit matcht nooit. Een via e-mail binnengekomen view-only account krijgt waarschijnlijk een 403 bij live delen. Vóór de tocht oplossen (§8).

## 2. Gebruikersflows

### 2.1 Leider (tijdsbudget ~3 min met bestaande route)
```
Missies-overzicht / kaart  ──►  [Groepstocht starten]  (primaire knop, naast "Nieuwe missie")

Stap 1/3  Basis
  Naam            [Wandeltocht 14 nov        ]   (voorgesteld, aanpasbaar)
  Wanneer         [14 nov] [09:00] — eind [16:00]
  Soort           (•) Training   ( ) Weekend/kamp   ( ) Clubtocht
                  → zet standaardinstellingen (zie §2.4)

Stap 2/3  Route en verzamelpunt
  [Teken of zoek]  [GPX importeren]  [Kies bestaande route]  [Alleen verzamelpunt]
  Kaart met route; sleep de 📍 voor het verzamelpunt
  Werkgebied: automatisch (route + 1 km)    Offline-pakket ± 120 MB   [aanpassen]

Stap 3/3  Uitnodigen
  ┌────────┐  https://app.milmap.nl/t/Xk3…   [Kopieer]
  │  QR    │  [WhatsApp] [E-mail] [Afdrukken]
  └────────┘
  ( ) Deelnemers zien elkaar     (•) Alleen de leiding ziet posities
  Max. deelnemers [25]   Link geldig tot [15 nov 16:00]

Klaar  ►  [Open livescherm]   [Zet kaart offline voor mij]   [Co-leider toevoegen]
```
Achter de schermen: één `Mission` (kind `tocht`), één Drive-kaart (+ route), een join-link. Co-leiders gaan via de bestaande collaborator-uitnodiging (editor/admin).

### 2.2 Deelnemer
```
Link / QR  ──►  /t/{token}  (publiek, geen login)
  "Wandeltocht 14 nov — georganiseerd door Anna"   route-voorbeeld op kaart
  [Doe mee]
      │
      ├─ Naam  [ Jan            ]
      ├─ ☑ Ik deel mijn locatie met de leiding tijdens deze tocht  (stop wanneer je wilt)
      ├─ [Kaart opslaan voor offline (118 MB, wifi aanbevolen)]
      └─ Tip: houd je scherm aan, of installeer de app voor tracking op de achtergrond

  Tochtscherm: kaart + route + jij + (leiding) · afstand/richting naar leider en verzamelpunt
               berichtenbalk (mededelingen van de leider) · [Stop met delen]
```
Heeft de deelnemer de app: de link opent via `milmap://t/{token}` en gebruikt het eigen account (viewer-rol), met achtergrondlocatie en E2EE-chat.

### 2.3 Tijdens de tocht (leider)
Livescherm = `MissionLiveMap` uitgebreid met:
- Deelnemerslijst met status: onderweg, stilstaand >10 min, geen signaal sinds X min, batterij laag.
- Mededeling versturen (tekst, optioneel met kaartpunt: "Verzamelen hier"). Deelnemers zien die direct bovenaan en als pin.
- Gasten verwijderen/hernoemen; de link intrekken.
- Knop **Tocht beëindigen**.

### 2.4 Voorinstellingen (drie, geen jargon)
| Soort | Standaard | Extra |
|---|---|---|
| Training | positie: alleen leiding · taken aan · O-groep optioneel | controlepunten, evaluatie na afloop |
| Weekend/kamp | positie: alleen leiding (er kunnen minderjarigen meedoen) · meerdere dagen later | ouderlijke toestemming blijft bij de leider |
| Clubtocht | positie: iedereen ziet iedereen · pauzepunten | recap deelbaar |

### 2.5 Einde
Leider beëindigt (of eindtijd + 3 uur verloopt):
1. Delen stopt voor iedereen; de link wordt ongeldig voor nieuwe deelnemers.
2. Posities van gasten worden 24 uur na afloop verwijderd. De leider bewaart het groepsspoor als track als dat aan staat.
3. Recap-pagina `/t/{token}/recap`: route, afstand, duur, optioneel flyover en foto's, plus "Maak een gratis account om je eigen tochten te plannen". Dit is de viral loop.

## 3. Toegangsmodel: drie lagen

| Laag | Wie | Kan | Beperking |
|---|---|---|---|
| **Kijker** | link geopend, geen naam | route en verzamelpunt zien | geen positie, geen lijst |
| **Web-deelnemer** | naam + toestemming, geen account | eigen positie delen (scherm aan), mededelingen lezen, afstand tot leider | geen achtergrond-GPS op iOS, geen E2EE-chat |
| **App-deelnemer** | account in de app | alles incl. achtergrond-GPS, chat, offline, tracks | account nodig |

Aanbeveling: gasten krijgen een **eigen, geïsoleerde gastsessie** (niet een Sanctum-gebruiker), zodat de bestaande autorisatie en premium-poorten niet worden verruimd. Een gast kan later een account aanmaken en de sessie "overnemen" (vergelijkbaar met `InvitationService::convertForNewUser`).

Ingelogde deelnemers erven `live_location` zolang de tocht actief is en de eigenaar betaalt (zoals team-leden nu het team-plan erven).

## 4. Datamodel

Nieuw (alle id's UUID, in lijn met de rest):

**`missions`** (uitbreiden)
- `kind` string, nullable (`null` = klassieke missie, `tocht`)
- `tocht` JSON, nullable: `{ starts_at, ends_at, meetup:{lat,lon,label}, preset, positions:'leaders'|'everyone', guests_enabled, max_guests, offline:{bbox,zoom_max,est_bytes} }`

**`mission_join_links`**: `id, mission_id, token_hash, role ('participant'), expires_at, max_uses, uses, created_by, revoked_at`
Token minimaal 128 bit, alleen de hash opslaan.

**`mission_guests`**: `id, mission_id, join_link_id, display_name, color, token_hash, user_id (nullable, bij overname), joined_at, last_seen_at, left_at`

**`mission_guest_positions`**: één rij per gast (upsert): `guest_id, lat, lon, accuracy, heading, speed, battery, recorded_at`. Opruimen 24 uur na afloop.

**`mission_announcements`**: `id, mission_id, author_id, kind ('info'|'regroup'|'alert'), body, lat, lon, created_at`

Statusafleiding (geen kolom): stilstaand = laatste 10 min < 30 m verplaatst; geen signaal = `recorded_at` > 3 min; batterij laag < 15%.

## 5. API (concept)

**Leider (Sanctum, bestaande mission-autorisatie)**
- `POST /missions/{id}/join-links` · `GET` · `DELETE /missions/{id}/join-links/{link}`
- `GET /missions/{id}/tocht/live` — samengevoegde deelnemers (gebruikers uit `user_locations` + gasten), met status
- `POST /missions/{id}/announcements`
- `POST /missions/{id}/tocht/end`
- `DELETE /missions/{id}/guests/{guest}`

**Gast (publiek, token)**
- `GET /api/v1/tocht/join/{joinToken}` — preview: naam, leider (voornaam), datum, gebied, route (vereenvoudigd). Geen posities.
- `POST /api/v1/tocht/join/{joinToken}/guests` `{display_name}` → `{guest_token, guest_id, color}`
- `GET /api/v1/tocht/g/state?since=` (header `X-Guest-Token`) → tocht, route, deelnemers (alleen bij `positions=everyone`), mededelingen, `server_time`
- `POST /api/v1/tocht/g/position`
- `POST /api/v1/tocht/g/leave`

Realtime: productie draait zonder Reverb (zie notitie *prod-server-config-gotchas*), dus polling: 10 s op voorgrond, 30 s op achtergrond, met `since`/ETag om payload klein te houden.

**Throttling per token, niet per IP.** Groepen zitten vaak achter één NAT (zie *school-nat-rate-limit-audit*). Sleutel op `guest_token`/`join_token`.

## 6. Privacy, veiligheid en misbruik

- Toestemming vooraf, zichtbaar wie wat ziet ("alleen de leiding" of "de hele groep") en één knop om te stoppen.
- Standaard "alleen leiding" bij Weekend/kamp en wanneer minderjarigen mogelijk meedoen; de leider is verantwoordelijk voor ouderlijke toestemming. Dit hoort in de gesprekken getoetst te worden.
- Posities van gasten zijn niet E2EE (de server relayt ze). Zeg dat eerlijk; E2EE blijft voor chat en kaartdata van accounts.
- Bewaartermijn gastposities: 24 uur na afloop. Rijen van `mission_guests` anoniem maken na 30 dagen.
- Misbruik: hoge-entropie tokens, intrekken, `max_uses`, verloopdatum, limiet gasten per plan (voorstel: gratis 5 · premium 25 · team 100, via de `plan_permissions`-matrix), schrijf-throttle per gast (1 positie per 5 s), alleen toegestane velden, geen vrije tekst behalve de naam (lengte- en tekenfilter).
- Linken niet loggen in analytics; geen namen in URL's.

## 7. Offline voor de groep

- Voeg bereik `tochtgebied` toe aan `startOfflineDownload` (leest nu `mapArea`); bereken bbox uit route + 1 km en toon de schatting vooraf.
- Leider: "Zet offline voor mij" op het missiescherm. Deelnemer: één knop op de landingspagina.
- **Licentie-controle:** de standaardkaart is Mapbox; eigen docs (`mapbox-overview.md`) stellen dat de voorwaarden geen herdistributie van tegels kennen. Voor NL-groepen dus de **PDOK BRT/stafkaart** als standaard voor het offline-pakket (eigen proxy, CC-BY). Voor andere landen eerst de Mapbox-voorwaarden voor offline gebruik nalopen vóór lancering.
- Web-deelnemers: tegelcache en service worker op echte toestellen testen; op iOS Safari zijn opslaglimieten streng.

## 8. Bouwvolgorde (grove schatting, niet gevalideerd)

**Sprint 0: voorwerk. KLAAR 2026-10-30 (niet gecommit, niet gedeployed)**
- ✅ `EnsureNotViewOnly`-regex repareren (UUID i.p.v. `\d+`) + test: `app/Http/Middleware/EnsureNotViewOnly.php`, `tests/Feature/ViewOnlyLiveLocationTest.php` (5 tests; oude regex gaf 0 treffers op een UUID-pad).
- ✅ Deel-knop op het missiescherm: pil "Locatie delen" in de balk van `components/missions/MissionLiveMap.vue` (gebruikt `locationSharingStore`, hergebruikt i18n-sleutel `locationSharing.share_toggle`, dus geen nieuwe vertalingen). Lokaal in de browser geverifieerd: aan → positie op de server, uit → sessie opgeruimd.
- ⏩ "Drive-kaart afdwingen bij een tocht" is verplaatst naar Sprint 1: er bestaat nog geen tocht (`kind`), dus er is nog niets om af te dwingen.
- Bekend, bewust niet aangepast: `locationSharingStore._misschienStoppenZonderTeam()` zet delen na 3 lege rondes zelf uit. Een leider die vooraf alleen op het verzamelpunt staat valt daardoor uit. Aanpassen in Sprint 1 (niet auto-stoppen zolang een tocht actief is).

**Sprint 1, ~8–10 dagen: wizard + link voor app-gebruikers**
- `kind`/`tocht`-kolommen, `mission_join_links`, wizard (3 stappen, 3 voorinstellingen).
- Openbare landingspagina `/t/:token` met preview; "Doe mee" voor ingelogde gebruikers (viewer + erfde `live_location`).
- Leiderslivescherm met statuschips; mededelingen.

**Sprint 2, ~10–12 dagen: gasten**
- `mission_guests`, gast-API, position-lus met Screen Wake Lock, gastscherm.
- Samengevoegde deelnemerslijst op het leiderscherm; verwijderen/intrekken.

**Sprint 3, ~6–8 dagen: offline + einde**
- Bereik `tochtgebied`, "offline voor iedereen", einde-flow, opruiming, recap-pagina.

**Daarna (P2):** achterblijvers/geofence/SOS, meerdaagse tochten, `participant`-rol met per-tab zichtbaarheid.

## 9. Meetpunten

Per tocht: tijd van "Groepstocht starten" tot eerste gedeelde link · uitgenodigd → link geopend → naam ingevuld → positie gedeeld (trechter) · aandeel verse posities (<2 min) tijdens de tocht · gast → account na recap · per leider: aantal tochten in 30 dagen. Gebruik `app_events` en `v-track`.

## 10. Risico's

1. **iOS-achtergrond-GPS voor web-gasten.** Beloof niet te veel; test op echte toestellen. De app blijft de betrouwbare route.
2. **Mapbox-licentie voor offline-pakketten** buiten NL (zie §7).
3. **Gastsessies vergroten het aanvalsoppervlak** (publieke schrijf-endpoint). Houd ze geïsoleerd van Sanctum en beperk velden en frequentie.
4. **Minderjarigen en toestemming** bij scouting: juridisch toetsen vóór de scouting-lancering.
5. **Scope.** Houd Sprint 1 klein genoeg om vroeg met 2–3 groepen te testen voor gasten worden gebouwd.

## 11. Open beslissingen

1. Gasten zonder account in v1 (Sprint 2) of eerst alleen app-gebruikers (Sprint 1)? Voorstel: Sprint 1 eerst opleveren en testen, gasten direct erna.
2. Standaardzichtbaarheid van posities: alleen leiding (voorstel) of iedereen?
3. Naam: "Groepstocht" past bij clubs en scouting; reservisten/airsoft zeggen eerder "oefening" of "activiteit". Testen in de gesprekken.
4. Eigen `participant`-rol (met per-tab zichtbaarheid) nu of later? Voorstel: later; begin met viewer + gefixte regex.
5. Gastlimieten per plan (voorstel in §6).
