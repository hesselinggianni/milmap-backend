# Groepstocht: sprintketen (opgezet 2026-10-30)

Uitvoeringsplan bij [groepstocht-ontwerp.md](groepstocht-ontwerp.md). Elke sprint is een eigen taak/sessie die zelfstandig wordt opgepakt, na elkaar. Eén sprint tegelijk: alle sprints raken dezelfde bestanden (`routes/api.php`, `MissionView.vue`, `MissionLiveMap.vue`, router, locales).

> **OP HOLD (2026-10-30).** De gebruiker stopte dit: de tocht werd binnen de missie-omgeving gebouwd, maar dat hoeft niet. Nieuwe richting: route-ontdekking in de routeplanner, zie [route-ontdekking-ontwerp.md](route-ontdekking-ontwerp.md). Sprint 1 is na ~2 minuten gestopt zonder bestanden te wijzigen. Start deze sprints NIET, tenzij de gebruiker dat zegt; een groep-op-een-route komt later kaart-gebonden terug (Fase 4 van het nieuwe ontwerp), niet missie-gebonden.

## Statusoverzicht (werk dit bij als je klaar bent)

| Sprint | Inhoud | Status |
|---|---|---|
| 0 | Regex-fix view-only, deel-knop op missiescherm | KLAAR 2026-10-30 |
| 1 | Tocht-kind, wizard, join-link, landingspagina, leiderslivescherm, mededelingen | OP HOLD (gestopt, niets gewijzigd) |
| 2 | Gastsessies zonder account (web), positie-lus, samengevoegde deelnemerslijst | OP HOLD |
| 3 | Offline-pakket voor de groep, einde-flow, opruiming, recap-pagina | OP HOLD |

## Regels voor elke sprint

1. **Lees eerst** [groepstocht-ontwerp.md](groepstocht-ontwerp.md) en [komoot-strategie.md](komoot-strategie.md) §8. Het ontwerp is leidend; wijk er alleen met reden van af en schrijf die reden in het ontwerp.
2. **Niet committen, niet deployen, geen productie-migraties.** Alleen lokaal bouwen en testen. De gebruiker beslist over commit en deploy. De werkmap bevat uncommitted werk van andere sessies: raak bestanden die niet bij jouw sprint horen niet aan en draai niets terug.
3. **Kleine, atomaire edits** in gedeelde grote bestanden (`routes/api.php`, `View.vue`, `MissionView.vue`, locale-JSON's). Andere sessies bewerken die tegelijk.
4. **Tests:** schrijf PHPUnit-tests voor nieuwe backendlogica (`php vendor/bin/phpunit <pad>`, sqlite in-memory) en draai de bestaande tests die je raakt (o.a. `CreateFlowsTest`, `MissionLiveEditingTest`, `InvitationIdentityTest`, `ViewOnlyLiveLocationTest`).
5. **Lokaal in de browser verifiëren** met `preview_start` (`milmap-backend-dev-sqlite` op :8000 en `milmap-frontend` op :8090; zie geheugennotitie *milmap-local-dev-sqlite*). Valkuilen die al eens tijd kostten:
   - Het cookie `milmap_token` wordt gedeeld over alle `localhost`-poorten. Een andere sessie kan je token overschrijven; een plotselinge 403/401 betekent dan "ander account", niet "kapotte code". Controleer het token vóór je de code verdenkt.
   - Verborgen browserpane = splash blijft hangen: `tabs_select` op de tab. HMR van andere sessies herlaadt de pagina vaak; houd scripts kort.
   - Een nieuwe missie opent met de waarschuwingsbevel-gate. Omzeilen in tests: zoek de Vue-instantie met `wngoGateActive` en zet die uit.
   - Gebruik testaccounts die je zelf registreert op `@local.test`; nooit echte accounts of productiegegevens.
6. **i18n:** nieuwe teksten in alle 12 app-locales (`nl, en, de, es, fr, it, ja, lt, pl, pt, tr, uk`) in `MilMap-Frontend/src/locales/`. De bestanden zijn round-trip-veilig (`JSON.stringify(json, null, 2)`), dus een klein node-script mag ze patchen. Geen letterlijke `@` in locale-strings (escape als `{'@'}`). Geen hardcoded NL in nieuwe UI.
7. **UI:** nooit een native `<select>` (gebruik `MSelect`), knoppen min. 44px op touch, container-responsive zijbalkinhoud, Lucide-iconen/geen emoji in UI.
8. **Throttling per token/gebruiker, niet per IP** (groepen zitten achter één NAT; zie geheugennotitie *school-nat-rate-limit-audit*).
9. **Raak Stripe, facturatie en de teams-/seatlogica niet aan.** De Stripe-prijs `team_extra_seat` is een openstaande stap van de gebruiker.
10. **Afsluiten:** werk de statustabel hierboven bij (KLAAR of DEELS, met wat er niet af is), zet een korte regel in de geheugennotitie `komoot-competitive-strategy`, en geef een eindrapport met: wat gebouwd is, wat je lokaal hebt geverifieerd, wat niet, nieuwe migraties, en eventuele bugs of beslissingen voor de gebruiker. **Begin niet aan de volgende sprint.**

## Vastgelegde beslissingen (standaard tot de gebruiker anders zegt)

- Naam in de UI: **Groepstocht**. Voorinstellingen: Training, Weekend/kamp, Clubtocht.
- Gasten zonder account komen in Sprint 2 (niet eerder).
- Standaardzichtbaarheid van posities: **alleen leiding**; bij Clubtocht "iedereen ziet iedereen".
- Deelnemers die inloggen krijgen de bestaande rol `viewer` (nog geen aparte `participant`-rol).
- Gastlimieten per plan (voorstel): gratis 5, premium 25, team 100, via `plan_permissions` of config. Standaard max. 25 per tocht als er geen plan-info is.
- Gastposities worden 24 uur na afloop verwijderd.
- Gebruikers kunnen dit later aanpassen; leg afwijkingen vast in het ontwerp.

---

## Sprint 1: tocht-kind, wizard, join-link (voor ingelogde gebruikers)

**Doel:** een leider maakt in < 3 minuten (met bestaande route) een groepstocht, deelt een link/QR, en ingelogde deelnemers kunnen meedoen en live delen. Gasten zonder account zijn Sprint 2.

**Bouw**
1. **Backend**
   - Migraties (alleen lokaal draaien): `missions.kind` (nullable string, `tocht`) en `missions.tocht` (JSON: `starts_at, ends_at, meetup{lat,lon,label}, preset, positions ('leaders'|'everyone'), guests_enabled, max_guests, offline`), tabel `mission_join_links` (id uuid, mission_id, token_hash, role 'participant', expires_at, max_uses, uses, created_by, revoked_at), tabel `mission_announcements` (id uuid, mission_id, author_id, kind info|regroup|alert, body, lat, lon, created_at).
   - Tocht aanmaken: breid `MissionController::store` uit (of een dunne `TochtController`) zodat `kind=tocht` automatisch een Drive-kaart maakt en koppelt als er geen is, de gratis-limiet en `validateMapLink` respecteert, en optioneel direct een join-link teruggeeft.
   - Join-links: `POST/GET/DELETE /missions/{id}/join-links` (alleen eigenaar/admin), publieke preview `GET /api/v1/tocht/join/{token}` (naam, leider-voornaam, tijd, gebied, vereenvoudigde route, GEEN posities, geen e-mails), `POST /api/v1/tocht/join/{token}/accept` (ingelogd: wordt `MissionCollaborator` met rol `viewer`, status `accepted`, respecteert `max_uses`, verloop, intrekking). Tokens ≥128 bit, alleen hash opslaan.
   - Rechtenerfenis: een geaccepteerde deelnemer van een ACTIEVE tocht mag live delen op de tochtkaart zolang de eigenaar premium/team-toegang heeft (vergelijk hoe teamleden het team-plan erven in `User::hasFeature()`/`RequiresPremium`). Zonder dit krijgt een deelnemer na zijn 7 proefdagen een 402.
   - Mededelingen: `POST /missions/{id}/announcements` (leiding), lezen via de bestaande mission-state of `GET /missions/{id}/announcements?since=`.
   - Tocht beëindigen: `POST /missions/{id}/tocht/end` zet status `complete`, trekt join-links in, stopt delen voor deelnemers (positie-rijen voor de tochtkaart verwijderen).
   - Samengevoegde livelijst met statusafleiding (onderweg / stilstaand >10 min / geen signaal >3 min / batterij laag <15%) op basis van `user_locations`; uitbreiden met `battery` indien nodig.
2. **Frontend**
   - "Groepstocht starten" als primaire knop in `MissionsOverview.vue`/`MissionsSheet.vue` naast "Nieuwe missie"; wizard in 3 stappen (Basis, Route en gebied, Uitnodigen) met 3 voorinstellingen. Hergebruik `CreateMissionSheet`, `LinkMapDialog.createAndLink`, `MissionAreaPicker`, GPX-import (`ImportGpxDialog`). QR via de bestaande `qrcode`-dependency.
   - Openbare landingspagina `/t/:token` (voeg toe aan de publieke paden in `router/index.js`), preview + "Doe mee" (login/registreer-keuze zoals `InviteAccept.vue`, daarna accept en doorsturen naar de missie).
   - Leiderslivescherm: breid `MissionLiveMap.vue` uit met deelnemerslijst + statuschips, mededeling versturen (incl. verzamelpunt-pin), link intrekken, "Tocht beëindigen".
   - Deelnemersscherm voor ingelogde deelnemers (kaart, route, leider, mededelingen) op basis van het bestaande missiescherm; houd het eenvoudig.
   - `locationSharingStore._misschienStoppenZonderTeam()`: niet auto-stoppen zolang de gekoppelde tocht actief is.
   - i18n in 12 talen; analytics-events via `v-track`/`app_events` voor de trechter (wizard gestart, link gedeeld, link geopend, deelgenomen).
3. **Tests:** PHPUnit voor token-hash, verloop, `max_uses`, intrekken, accept-rechten (viewer, niet eigenaar), preview lekt geen e-mails/posities, rechtenerfenis, eindeflow, mededelingen-autorisatie. Handmatige browsercheck van wizard → link → tweede account accepteert → live delen → leider ziet statuschip → beëindigen.

**Klaar als:** bovenstaande flow werkt lokaal end-to-end met twee testaccounts, tests groen, status bijgewerkt, eindrapport geschreven. Niet gebouwd: gasten, offline-pakket, recap.

---

## Sprint 2: gastsessies zonder account

**Doel:** deelnemers kunnen via link of QR meedoen zonder account of installatie en hun positie delen (web, scherm aan), zonder bestaande autorisatie of premium-poorten te verruimen.

**Bouw**
1. **Backend (geïsoleerd van Sanctum)**
   - Tabellen: `mission_guests` (id uuid, mission_id, join_link_id, display_name, color, token_hash, user_id nullable, joined_at, last_seen_at, left_at) en `mission_guest_positions` (guest_id, lat, lon, accuracy, heading, speed, battery, recorded_at; één rij per gast, upsert).
   - Publieke API: `POST /api/v1/tocht/join/{joinToken}/guests {display_name}` → `{guest_token, guest_id, color}`; `GET /api/v1/tocht/g/state?since=` (header `X-Guest-Token`: tocht, route, deelnemers alleen bij `positions=everyone`, mededelingen, `server_time`); `POST /api/v1/tocht/g/position`; `POST /api/v1/tocht/g/leave`. Eigen middleware voor gast-token-auth (hash-vergelijking, geldig zolang tocht actief), GEEN Sanctum-gebruiker.
   - Throttle per `guest_token`/`join_token` (positie max. 1 per 5 s), veldwhitelist, naam-validatie (lengte, tekenset), gastlimiet per tocht/plan.
   - Leider: `GET /missions/{id}/tocht/live` voegt gastposities samen met `user_locations` (met bron-vlag); gast verwijderen/hernoemen.
   - Overname: een gast kan later een account aanmaken en de gastsessie koppelen (`user_id`), vergelijkbaar met `InvitationService::convertForNewUser`.
   - Opruimen: scheduler-/commandotaak die gastposities 24 uur na afloop verwijdert en gastnamen na 30 dagen anonimiseert.
2. **Frontend**
   - Landingspagina `/t/:token` uitbreiden: kijker → naam + toestemming → tochtscherm (route, verzamelpunt, leider, afstand/richting, mededelingen, "Stop met delen").
   - Positie-lus voor gasten met Screen Wake Lock (waar beschikbaar), eerlijke melding dat het scherm aan moet blijven of dat de app beter werkt, polling 10 s voorgrond / 30 s achtergrond met `since`.
   - Leiderslivescherm toont gasten naast accounts, met verwijderen.
   - i18n 12 talen; trechter-events (link geopend → naam ingevuld → positie gedeeld).
3. **Tests:** token-auth, throttle-sleutel, limiet, veldwhitelist, ingetrokken link/verlopen tocht, leider ziet gastposities, gast ziet anderen alleen bij `everyone`, opruim-commando, overname naar account. Browsercheck met twee gastcontexten (aparte tabs/privévenster) en een leider.

**Klaar als:** twee gasten en een leider werken lokaal end-to-end, tests groen, risico's (iOS-achtergrond-GPS) eerlijk in de UI benoemd, status bijgewerkt, eindrapport. Niet gebouwd: offline-pakket, recap.

---

## Sprint 3: offline voor de groep, einde en recap

**Doel:** de leider zet gebied + route met één tik offline voor iedereen, en na afloop is er een deelbare recap die deelnemers naar een account leidt.

**Bouw**
1. **Offline:** voeg bereik `tochtgebied` toe aan `offlineMapsMixin.startOfflineDownload` (leest nu `mapArea`; moet `mission.area`/`tocht.offline.bbox` kunnen gebruiken), toon schatting vooraf, knop "Zet offline voor mij" op het missiescherm en op de gast-/deelnemerslanding. Voor NL standaard de PDOK-kaartlaag (eigen proxy, CC-BY). **Controleer de Mapbox-voorwaarden voor offline gebruik** en leg de conclusie vast; bied buiten NL pas offline aan als dat mag, anders een duidelijke melding. Test web-gasten (service worker/tegelcache, iOS-limieten) en beschrijf wat wel/niet werkt.
2. **Einde-flow:** eindtijd + 3 uur laat een tocht automatisch aflopen (scheduler), delen stopt, links worden ongeldig, posities worden opgeruimd volgens het ontwerp; leider bewaart optioneel het groepsspoor als track.
3. **Recap:** publieke pagina `/t/{token}/recap`: route, afstand, duur, optioneel flyover en foto's (hergebruik `Routemap`-flyover/ShareView-patroon), zonder persoonlijke posities van gasten tenzij toegestaan; CTA "Maak een gratis account"; trechter-event voor gast → account.
4. **Tests + browsercheck:** autoafloop, opruiming, recap-privacy (geen gastnamen of -posities lekken), offline-bbox-berekening.

**Klaar als:** hele keten (aanmaken → delen → meedoen → live → afloop → recap) werkt lokaal, tests groen, status bijgewerkt, eindrapport met een lijst "wat nog moet vóór livegang" (deploy-volgorde: migraties, backend, frontend; open bugs; juridische toets minderjarigen).
