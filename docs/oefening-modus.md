# Oefening-modus (Exercise mode) — plan

Status: **volledig gebouwd (broks 1–6); backend integratie-getest, frontend compileert schoon + rendert** · Laatste update: 2026-07-12

## Voortgang

- ✅ **Brok 1** — migrations + Eloquent-modellen (exercise_sites, site_members,
  exercise_factions, exercise_participants, exercise_objectives,
  exercise_submissions + missions.game_mode/exercise_site_id). Gemigreerd.
- ✅ **Brok 2** — `ExerciseSiteController` (terrein-CRUD, kaart koppelen, huisregels
  + rules_version-bump, rollen via site_members) + rol-resolver
  `Mission::exerciseRoleFor()` + routes achter `RequiresPremium:exercise`.
- ✅ **Brok 3** — `ExerciseController` (setup, state, roster, partijen-CRUD, join,
  accept-rules, active met regels-gate, rol-override).
- ✅ **Brok 4** — `ExerciseObjectiveController`, `ExerciseSubmissionController`
  (submit + dubbel-blokkade + review-queue + approve/reject), `ExerciseScoreController`
  (scorebord speler + partij).
- ✅ **Brok 6** — geofence auto-detectie in submit (haversine + tijdvenster;
  verification auto=direct goedkeuren, both=pending na in-range, else controleur).
  *Let op:* echte hold_location-duur (X sec bezet houden) is nog niet gemeten —
  nu behandeld als momentopname; volledige duur-tracking via de locatie-stream is
  een follow-up.
- ✅ **Brok 5** — frontend gebouwd:
  - `ExerciseService.js` (alle endpoints).
  - `ExercisePanel.vue` (orchestrator, sub-tabs: Overzicht/Doelen/Kaart/Scorebord
    + Beoordelen voor controleur + Beheer voor commandant) — gemount in
    `MissionView.vue` wanneer `mission.game_mode === 'exercise'` (standaard missies
    ongewijzigd).
  - Kind-componenten: `ExerciseObjectives.vue` (claim-flow + geolocatie),
    `ExerciseScoreboard.vue` (poll), `ExerciseControllerQueue.vue` (review),
    `ExerciseAdmin.vue` (partijen/doelen/rollen/terrein-koppeling).
  - `ExerciseSitesView.vue` + route `/exercise-sites` (terrein-beheer: create,
    huisregels, rollen).
  - `MissionLiveMap.vue` uitgebreid met doel-zones (OL VectorLayer, cos-lat
    gecorrigeerde cirkels) + optionele `objectives`/`factions` props.
  - Instap: checkbox "Oefening-modus" in `CreateMissionSheet.vue`; backend
    `MissionController` store/update accepteren `game_mode`/`exercise_site_id` en
    `present()` geeft ze terug.
  - Geverifieerd: `vue-cli-service serve` compileert schoon (geen fouten),
    `ExerciseSitesView` rendert correct in de browser.

### Frontend-afwijkingen / follow-ups
- `appModeStore` kreeg GEEN 'exercise'-mode: dat is de opslag-as (drive/local),
  een andere as dan `game_mode`. Oefening wordt gedetecteerd via `mission.game_mode`.
- ✅ Nav-ingang toegevoegd: schild-knop "Terreinen" in de MissionsOverview-topbar
  → `/exercise-sites`.
- ✅ Deelnemer-pin-kleuren per partij: `ExercisePanel` bouwt `userColors`
  (user_id→partij-kleur via roster) en geeft die aan `MissionLiveMap`; pins krijgen
  een rand in partij-kleur (navigeren-groen heeft voorrang).
- Locatie delen bij "Actief meedoen" zet nu de backend-vlag; het live-streamen
  loopt nog via de bestaande locatie-deel-knop op de Kaart-tab (niet auto-gestart).
- UI-strings hardcoded NL (consistent met omliggende mission-componenten); i18n = follow-up.
- Live end-to-end test van de deelname-flow in de browser vergt inlog (niet gedaan);
  backend-flows zijn wél volledig integratie-getest.

Alle backend-broks zijn geverifieerd met integratie-scripts (rol-overerving,
regels-gate, deelname-flow, doel→actie→review→score, geofence).

### Afwijkingen t.o.v. oorspronkelijk plan (bewust)
- User-FK's zijn `unsignedBigInteger` (users hebben bigint-id), niet uuid.
- Facties/deelname/setup zijn geconsolideerd in één `ExerciseController` i.p.v.
  een aparte `ExerciseParticipantController` (cohesie).
- Deelname-POST's (join/accept-rules/active) staan bewust BUITEN de
  `RequiresPremium:exercise`-gate zodat gratis/uitgenodigde spelers kunnen meedoen;
  commandant-mutaties (setup, partijen, rol-override) zitten er wél achter.

---

Status-detail hieronder is het oorspronkelijke plan (nog steeds leidend voor brok 5).

Een nieuwe modus binnen MilMap voor **force-on-force oefeningen**: airsoft-games,
militaire trainingen (FTX/STX), en soortgelijke scenario's. Deelnemers kiezen een
partij, gaan akkoord met de terrein-regels, volgen de oefening en delen hun locatie
om actief mee te doen. Doelen (objectives) worden in het veld door een controleur
goedgekeurd, en er worden scores bijgehouden (per deelnemer én per partij).

Airsoft is één *smaak* van dit bredere concept — de terminologie is bewust generiek.

---

## 1. Kernbegrippen & naamgeving

| Begrip | Betekenis | EN |
|---|---|---|
| **Oefening** | Een missie in oefening-modus (`missions.game_mode = 'exercise'`) | Exercise |
| **Terrein** | Vaste locatie-entiteit met eigen kaart, huisregels en vaste rollen; hieraan koppel je oefeningen | Site |
| **Partij** | Team binnen één oefening; deelnemers kiezen hierin | Faction |
| **Doel** | Objective: taak met locatie- en/of tijd-eis | Objective |
| **Actie** | Ingediende claim van een deelnemer op een doel, door controleur beoordeeld | Submission |

### Rollen

| Rol | DB-waarde | Rechten |
|---|---|---|
| **Commandant** | `commander` | Alles. Maakt terrein + kaart, definieert partijen, doelen en regels, wijst controleurs aan, start/stopt de oefening, overrulet scores, ziet alle info. |
| **Controleur** | `controller` | Ziet oefening-specifieke info + deelnemers live op de kaart. Keurt ingediende acties **goed/af** in het veld, kent punten toe, kan een doel handmatig triggeren. Géén globale instellingen. |
| **Deelnemer** | `player` | Kiest partij, leest de brief, accepteert de regels, deelt locatie om actief mee te doen, dient acties in, volgt het scorebord. |

**Rol-resolutie** (`Mission::exerciseRoleFor($user)`):
1. `exercise_participants.role_override` gezet? → die.
2. Anders: `site_members.role` op het gekoppelde terrein.
3. Anders: `player`.

Dus je terrein-rol geldt standaard in elke oefening op dat terrein, maar de commandant
kan per oefening iemand promoveren/degraderen. `commander`/`controller` krijgen tevens
`editor`/`admin` op de onderliggende `mission_collaborators`, zodat de bestaande
edit-gates blijven werken.

---

## 2. Hergebruik van bestaande code

~70% is hergebruik. Oefening-modus is een spel-laag bovenop het missie-systeem.

| Behoefte | Bestaande bouwsteen | Toevoeging |
|---|---|---|
| Terrein + kaart instellen | `Map` model (`app/Models/Map.php`) + `views/maps/View.vue` (OpenLayers, layers/GeoJSON) | `ExerciseSite` omhult een `Map` |
| Oefening koppelen aan terrein | `Mission` (heeft al `map` json, `linked_team_id`, `ogroup`, `status`) | `missions.game_mode` + `missions.exercise_site_id` |
| App weet dat het een oefening is | `stores/appModeStore.js` (`drive`/`local`) | mode `'exercise'` |
| Partij kiezen als deelnemer | `Team`/`TeamMember` (org-niveau, blijft bestaan) | per-oefening **partijen** (deelnemers joinen zichzelf) |
| Rollen | `mission_collaborators.role` (viewer/editor/admin) blijft voor edit-rechten | aparte oefening-rol-laag (terrein + oefening) |
| Info/brief lezen | `views/missions/MissionView.vue` (SMEAC/O-group) | oefening-brief-sectie |
| Live meedoen = locatie delen | `user_locations` (map-scoped) + `components/missions/MissionLiveMap.vue` avatar-markers | partij-kleur op markers + doel-zones |
| Veld-communicatie | `components/maps/MapCollabChat.vue` + missie-kanaal (`chatStore`) | — (kant en klaar) |
| Premium-gate | `RequiresPremium:feature` → 402 | eigen feature-key **`exercise`** |

**Belangrijk:** live-locatie is *map-scoped* (`user_locations.map_id`), niet mission-scoped.
Dat past precies: het terrein heeft een vaste kaart, dus alle oefeningen op dat terrein
delen dezelfde live-kaart.

---

## 3. Datamodel (migrations)

Prefix `exercise_` / `site_`. Alle nieuwe tabellen UUID-pk, waar zinvol soft-deletes.

### 3.1 `exercise_sites` (het terrein)
```
id (uuid), owner_id (uuid FK users), name, description,
map_id (uuid FK maps), center_lat, center_lng,
house_rules (text), rules_version (int, default 1),
status (default 'active'), timestamps, soft delete
```

### 3.2 `site_members` (terrein-rollen = standaard)
```
id (uuid), site_id (FK exercise_sites), user_id (FK users),
role ENUM(commander|controller|player), added_by (uuid), timestamps
unique(site_id, user_id)
```

### 3.3 `exercise_factions` (partijen per oefening)
```
id (uuid), mission_id (FK missions), name, color,
spawn_lat (nullable), spawn_lng (nullable), timestamps
```
Kan getemplate worden vanuit het terrein (bv. standaard Rood/Blauw), per oefening aanpasbaar.

### 3.4 `exercise_participants` (deelnemer-in-een-oefening — kern van de flow)
```
id (uuid), mission_id (FK missions), user_id (FK users),
faction_id (nullable FK exercise_factions),
role_override ENUM(commander|controller|player) nullable,
accepted_rules_version (int, nullable), accepted_at (nullable),
status ENUM(active|inactive) default inactive, joined_at, timestamps
unique(mission_id, user_id)
```
Bevat teamkeuze, regels-akkoord én de per-oefening rol-override in één record.

### 3.5 `exercise_objectives` (doelen)
```
id (uuid), mission_id (FK missions),
faction_id (nullable FK — null = contested/iedereen),
title, description,
type ENUM(reach_location|hold_location|timed_task|free_action),
target_lat (nullable), target_lng (nullable), radius_m (nullable),
hold_seconds (nullable),          -- voor hold_location
window_start (nullable), window_end (nullable),
points (int),
verification ENUM(auto|controller|both) default both,
status default 'active', timestamps
```

### 3.6 `exercise_submissions` (ingediende acties → controleur-beoordeling)
```
id (uuid), objective_id (FK), mission_id (FK),
user_id (FK), faction_id (nullable FK),
status ENUM(pending|approved|rejected) default pending,
note (nullable), photo_path (nullable),
controller_id (nullable FK users), awarded_points (nullable),
submitted_at, reviewed_at (nullable), timestamps
```

### 3.7 Scores
Afgeleid uit goedgekeurde submissions (aggregatie per deelnemer + per partij).
Start met een **berekende endpoint**; later evt. gecachet in `exercise_scores` als
het scorebord traag wordt.

### 3.8 Wijzigingen op `missions`
```
game_mode ENUM(standard|exercise) default 'standard'
exercise_site_id (nullable FK exercise_sites)
```

---

## 4. Backend — API

Nieuwe controllers (write-routes achter `RequiresPremium:exercise`):

- **`ExerciseSiteController`** — CRUD terrein, kaart koppelen, huisregels +
  `rules_version` bumpen, controleurs beheren (`site_members`).
- **`ExerciseParticipantController`** — `join` (kies partij), `acceptRules`,
  `setActive` (locatie delen aan/uit), roster.
- **`ExerciseObjectiveController`** — CRUD doelen (commandant), lijst met status
  per deelnemer/partij.
- **`ExerciseSubmissionController`** — `submit` (deelnemer, evt. foto),
  `review` (controleur approve/reject + punten), pending-queue.
- **`ExerciseScoreController`** — live scorebord (deelnemer + partij).

**Regels-gate:** `join` / `setActive` / `submit` weigeren zolang
`accepted_rules_version < site.rules_version` → forceert het terrein-regels-akkoord
vóór actieve deelname.

**Feature-key `exercise`:** los afschermbaar/prijsbaar (bv. voor airsoft-verenigingen),
zonder de kern-app te raken. Toevoegen aan `RequiresPremium` feature-mapping.

---

## 5. Frontend

- **Terrein-beheer**: nieuwe view `ExerciseSitesView.vue` — lijst + aanmaken,
  kaart-picker (hergebruikt `views/maps/View.vue` / `Map`), huisregels-editor,
  controleur-toewijzing.
- **Oefening-view**: `views/missions/MissionView.vue` uitbreiden — als
  `game_mode === 'exercise'` toon oefening-secties i.p.v. de militaire tabs:
  *Brief* → *Partij kiezen* → *Regels* (geblokkeerd tot akkoord) → *Kaart*
  (`MissionLiveMap` + doel-overlays) → *Doelen* (lijst + "actie indienen") → *Scorebord*.
- **Controleur-queue**: `ControllerReviewPanel.vue` — binnenkomende acties,
  goedkeuren/afkeuren, punten toekennen, deelnemers live op kaart.
- **Commandant-instellingen**: partijen + doelen definiëren (kaart-picker voor
  locatie + radius), controleurs aanwijzen, oefening starten/stoppen
  (hergebruik `missions.status` → `active`).
- **Live-map**: markers inkleuren op `faction.color`; doel-zones als cirkels op de
  OL-kaart.
- `stores/appModeStore.js` krijgt mode `'exercise'`.

---

## 6. Doelen-engine (objectives)

Types, oplopend in complexiteit:

- **`reach_location`** — binnen tijdvenster binnen radius R van punt P zijn.
  Kan **auto-detecteren** via de live-locatie-stream (geofence), of controleur-bevestigd.
- **`hold_location`** — punt `hold_seconds` bezet houden.
- **`timed_task`** — "doe vóór tijdstip T iets" → deelnemer dient in, controleur keurt goed.
- **`free_action`** — vrije actie, volledig controleur-beoordeeld.

Elk doel heeft `verification: auto | controller | both`. **Default `both`**:
auto-geofence detecteert nabijheid, maar een controleur bevestigt (tegen valsspelen).
Per doel omzetbaar naar puur `auto` of puur `controller`.

---

## 7. Bouwvolgorde ("alles in één keer", maar testbaar per brok)

1. **Backend fundament** — migrations §3.1–3.8 + Eloquent-modellen + relaties +
   `game_mode`/`exercise_site_id` op Mission.
2. **Terrein + rollen + regels** — `ExerciseSite` + `site_members` CRUD,
   rol-resolver, regels-akkoord-gate.
3. **Partijen + deelname** — join/partij-keuze, `setActive` → koppelt aan bestaande
   locatie-deel-flow.
4. **Doelen + acties + controleur-review + scores** — de gameplay-kern.
5. **Frontend** — terrein-beheer, oefening-view-secties, controleur-queue, scorebord,
   partij-kleuren + doel-zones op de kaart.
6. **Geofence auto-detectie** (`reach_location`/`hold_location`) — leunt op de
   live-locatie-stream die al binnenkomt; `verification: auto` sluit de loop zonder
   controleur.

---

## 8. Open punten / later

- Foto-bewijs bij acties (opslag + privacy) — welke disk, retentie?
- Historie/statistieken per deelnemer over meerdere oefeningen.
- Terrein-brede standaard-partijen als template.
- Notificaties (push) bij nieuwe actie voor controleurs / bij goedkeuring voor deelnemers.
- Veiligheid/safety-call ("cease fire") als aparte broadcast op de oefening.

---

## Referentie — bestaande bestanden

- Mission: `app/Models/Mission.php`, migraties `2026_06_05_140000_create_missions_table.php` e.v.
- Teams: `app/Models/Team.php`, `app/Models/TeamMember.php`
- Rollen/gate: `app/Http/Middleware/RequiresPremium.php`, `app/Models/MissionCollaborator.php`
- Live locatie: `app/Models/UserLocation.php`, `app/Http/Controllers/UserLocationController.php`,
  frontend `src/components/missions/MissionLiveMap.vue`
- Chat: `app/Http/Controllers/MissionCommsController.php`, `src/stores/chatStore.js`,
  `src/components/maps/MapCollabChat.vue`
- Maps: `app/Models/Map.php`, `app/Models/RouteMap.php`, `src/views/maps/View.vue`
- App-modus: `src/stores/appModeStore.js`
- Missie-view: `src/views/missions/MissionView.vue`
