# KAKO app-modus — ontwerp

Status: **ontwerp klaar, beslispunten bevestigd, fase 1 gebouwd en lokaal getest** (2026-10-31, zie §12). Niets gedeployed, niets gecommit.
Hoort bij [kaart-en-kompas-opdracht.md](kaart-en-kompas-opdracht.md) (hoofdstuk "Lopen met papier of met de app").

## 0. In het kort

Een instructeur nodigt deelnemers uit om een KAKO-opdracht **in de app** te lopen. Voor die deelnemer
is MilMap dan een afgeschermde "deelnemer-cockpit": eigen route, starttijd, uiterlijk-binnen,
**check-in per prikker** ("ik ben hier", met meerdere pogingen, per handmatig/QR/NFC) en — met
toestemming en alleen tijdens het venster — **positie delen met de instructeur**. De instructeur ziet
live wie waar is, een check-in-log en achteraf wie **samen heeft gelopen** en met welke tussenafstanden.

Wat dit ontwerp vooral beslist:

1. **Privacy bepaalt de vorm** (§9): positie delen is een apart, expliciet, intrekbaar toestemmingsmoment,
   server-side beperkt tot het tijdvenster, met bewaartermijn, inzage en wissen. Geen verborgen analyse.
2. **Afscherming is afschrikking + zichtbaarheid, geen examenbewaking** (§7). We zeggen dat eerlijk in de app.
3. **Server-side waar het kan, client-side voor de rest**: de deelnemer krijgt van de server alleen z'n eigen
   route zonder coördinaten van prikkers; tijdens het venster weigert de server (423) de functies die de
   instructeur heeft uitgezet.
4. **Bouwen in fasen** (§11): eerst data + API + minimale deelnemer-weergave (fase 1), dan instructeur-UI en
   afscherming, dan "samen gelopen", dan QR/NFC, dan achtergrond-GPS/alarmen.

## 1. Uitgangspunten

- **Deelnemer ≠ medewerker.** Een app-deelnemer staat *niet* in `route_assignment_collaborators` en krijgt
  *geen* kaarttoegang. Daardoor geeft `RouteAssignmentController::roleFor()` hem `null` en weigert
  `GET /route-assignments/{id}` hem (403): hij kan de opdracht (alle deelnemers, alle posten) niet opvragen.
  Zijn enige ingang is de eigen deelnemer-API (§4).
- **Dataminimalisatie**: alleen opslaan wat nodig is voor check-in-bewijs, veiligheid en de "samen"-analyse;
  geen accu-%, geen contacten, geen apparaatinfo buiten een korte `device`-tekst.
- **Privacy by default**: zonder toestemming én zonder actief venster wordt er geen enkele positie bewaard —
  de server weigert het, ook als de app zich misdraagt.
- **Niemand gegijzeld**: de deelnemer kan afgeschermde modus altijd verlaten (wordt gelogd, instructeur ziet het).
  Veiligheid (verdwaald, 112 bellen) gaat voor examendiscipline.
- **Offline-eerst voor check-ins** (bestaande outbox), **best-effort voor posities** (eigen buffer).
- **Alles wat we loggen is voor de deelnemer inzichtelijk** (zelfde data, eigen weergave).

## 2. Wat er al is (repo-onderzoek 2026-10-31)

| Onderdeel | Staat | Gevolg voor het ontwerp |
|---|---|---|
| `route_assignments` (+ `participants` JSON `[{id,name,route_code,order,result}]`, `settings` JSON) | gebouwd, live | deelnemer-sleutel = `participants[].id`; app-instellingen komen in `settings.appMode` |
| `settings.mode` (paper/app/both) en `settings.restrictions` (navigation, tools, locationSharing, sidebar, routemap, editing) | worden bewaard, **niet afgedwongen** (UI zegt dat zelf) | semantiek vastleggen: **`true` = toegestaan**, `false` = geblokkeerd (preset "Examen" = alles `false`) |
| Productiemodus (`settings.status = live`) + **423** `locked` | gebouwd | `settings.appMode` valt automatisch onder de vergrendeling → voorwaarden wijzigen tijdens een uitvoering kan niet |
| `route_assignment_collaborators` (editor/viewer, accepteren) en `route_assignment_logs` | gebouwd | bewerkingslog blijft voor wijzigingen; deelnemer-events krijgen een **eigen** tabel (de bewerkingslog is ook voor *viewers* leesbaar) |
| `kako_groups` (sibling-sessie, 2026-10-31): leden `{id,name,device_id?}` met Connect-apparaat-id | in aanbouw elders | Connect-apparaat → `user_id` van het apparaat-account: dé natuurlijke manier om scholen/ploegen aan te koppelen zónder persoonlijke uitnodiging (§3) |
| `UserLocationController` + `user_locations` (1 rij/gebruiker/kaart, 2-min-versheid, `RequiresPremium:live_location`) | gebouwd | **niet hergebruiken**: kaart-gebonden, premium, kent geen venster/toestemming/historie. Wél hergebruiken: `GpsValues::normalize` (heading/speed -1) en het polling-patroon (prod heeft geen Reverb) |
| `MissionLiveController` / mission-live-participants-map | gebouwd | UI-patroon voor de live instructeurskaart (inline OL-kaart met avatars + detail-sheet) |
| `EnforceConnectManagement` | gebouwd | Connect-beleid `share_location=false` moet positie-delen blokkeren; nieuwe schrijf-endpoints vallen nu onder het generieke `edit`-recht → **aandachtspunt** (§10, fase 3) |
| Outbox (`src/services/outbox.js`, IndexedDB `milmap-outbox`, `QUEUEABLE` URL-regexen, ordering, optimistische respons) | gebouwd | check-ins: één regex erbij. Posities: **niet** in de outbox (volume, UI-ruis, moet bij intrekken meteen leeg kunnen) |
| `@capacitor/geolocation` + `@capacitor-community/background-geolocation`, `ACCESS_BACKGROUND_LOCATION`, `NSLocationAlwaysAndWhenInUse…`, `bgLocationDisclosure.js` | aanwezig | achtergrond-GPS is technisch mogelijk (fase 5); de prominente-uitleg-flow bestaat al |
| Camera: `NSCameraUsageDescription`, Android `CAMERA`; `jsqr` + `qrcode` als dependency | aanwezig; **geen actief `jsQR`-gebruik gevonden in `src/`** (de chat-QR-koppeling uit het geheugen staat niet in deze werkmap) | QR-scannen in de app is haalbaar zonder native werk (getUserMedia + jsQR); QR-codes tekenen kan met `qrcode` |
| NFC | **niets**: geen plugin, geen `NFCReaderUsageDescription`, geen Core-NFC-entitlement, geen `android.permission.NFC` | zie §6: NFC is een apart traject met native/store-gevolgen |
| Deep links | `milmap://` (iOS + Android), universal/app links **alleen `/invite/*`** (AASA + `assetlinks`/manifest) | `/kako/…`-links openen de app nog niet vanuit de browser/NFC; uitbreiden = AASA + manifest + nieuwe build (fase 4) |
| Bruno `milmap-api-tests/kako/` (42 requests, 2 gebruikers via `run-local.sh`) | gebouwd | nieuwe tests als `50-…` t/m; tweede gebruiker = deelnemer |

## 3. Rollen en uitnodigen

| Rol | Wie | Mag |
|---|---|---|
| **Eigenaar** | `route_assignments.owner_id` | alles, incl. deelnemersdata, venster, intrekken |
| **Bewerker** (expliciet) | geaccepteerde collaborator `editor` | deelnemersdata zien, venster beheren (standaardkeuze B3) |
| Kijker / impliciete kaartbewerker | collaborator `viewer`, of via MapPolicy | **geen** posities/check-ins (alleen de opdracht zelf, zoals nu) |
| **App-deelnemer** | account gekoppeld aan een `participants[].id` | eigen payload, eigen check-ins/posities, eigen toestemming |

`roleFor()` geeft impliciete kaartbewerkers de rol `editor`. Voor deelnemersdata is dat te ruim: een
collega die toevallig de kaart mag bewerken zou plots live posities zien. Daarom een aparte check
`canSeeParticipantData()` (eigenaar of **geaccepteerde, expliciete** editor-collaborator).

**Koppelen deelnemer ↔ account** (fase 1: persoonlijke code; later aanvullend):

1. **Persoonlijke code/link** (fase 1): per deelnemer een code van 10 tekens (geen 0/O/1/I), 14 dagen
   geldig, eenmalig te claimen: `https://app.milmap.nl/kako/deelnemen/<code>` of invoeren in de app. De
   deelnemer logt in (gratis account volstaat — **geen premium nodig** voor deelnemen), bevestigt "Ik ben
   Jansen" en geeft toestemming (of kiest papier). Ook als QR op het briefingblad te printen.
   Voordeel boven e-mail: werkt zonder dat de instructeur e-mailadressen heeft, en niemand kan een
   andermans naam kiezen (de code is de binding).
2. **Connect-apparaat / school** (fase 2, samen met de groepen-sessie): een groepslid met `device_id` →
   het apparaat-account (`connect_device_account`) wordt direct `user_id`; de deelnemer hoeft niets te claimen,
   alleen te bevestigen (toestemming blijft persoonlijk, §9).
3. **E-mail-uitnodiging** (optioneel, fase 2): zoals bij collaborators, alleen bestaande accounts.

Waarschuwing in de instructeur-UI (fase 2): "deze persoon heeft ook toegang tot de kaart" — wie de kaart mag
zien, kan de posten en routes van iedereen zien; dat hoort bij een examen niet. De deelnemerslijst-API
levert daarvoor `has_map_access`.

## 4. Datamodel (nieuwe tabellen, alle `kako_app_*`)

Bewust nieuwe tabellen en **geen** wijzigingen in `route_assignments`: de bestaande controller blijft
onaangeroerd, en de privacygevoelige data heeft eigen levensduur en eigen FK-gedrag.

### `kako_app_participants` — koppeling + toestemming + venster + laatste positie

| Kolom | Opmerking |
|---|---|
| `id`, `route_assignment_id` (uuid, cascade), `participant_key` (= `participants[].id`) | unique `(assignment, key)` |
| `user_id` (nullable, **nullOnDelete**), `display_name` | naam-momentopname; blijft kloppen na account-verwijdering (zoals `route_assignment_logs.user_name`) |
| `status` | `invited` → `accepted`; `declined` (kiest papier), `revoked` (instructeur) |
| `invite_code`, `invite_expires_at` | eenmalig; leeg na claimen |
| `consent_version`, `consent_at`, `consent_withdrawn_at` | **alleen voor positie-delen** (§9); check-in zelf is onderdeel van deelnemen |
| `window_from`, `window_until` | het tijdvenster; leeg = nog niet gestart. Max. 12 uur per venster (vergeet-beveiliging) |
| `last_lat/lon/accuracy`, `last_position_at`, `last_seen_at` | gedenormaliseerd voor de live-kaart (1 rij lezen per deelnemer, niet de sample-tabel) |
| unique `(assignment, user_id)` | één account = één deelnemer per opdracht (NULL's botsen niet) |

### `kako_app_checkins` — elke poging, ook de mislukte

`id`, `route_assignment_id`, `participant_id` (cascade), `user_id` (nullOnDelete), `client_uuid`
(**idempotentie**, unique `(participant_id, client_uuid)`), `post_id` + `post_label` (momentopname) + `role`
(`post`|`start`|`finish`), `method` (`manual`|`qr`|`nfc`), `attempted_at` (ms; GPS-fixtijd bij voorkeur, anders
apparaattijd, begrensd op ontvangsttijd + 60 s), `received_at`, `deadline_at` (momentopname van de cut off tijd, §13), `lat/lon/accuracy`, `distance_m`,
`within_radius`, **`result`** (`ok` · `too_far` · `no_position` · `wrong_code` · `not_on_route` ·
`method_not_allowed` · `attempts_exhausted` · `outside_window`), `device` (kort, bv. "ios 1.38").

- Elke geldige aanvraag levert een rij en HTTP 201/200 — óók `too_far`/`outside_window` (anders faalt een
  offline herhaling uit de outbox). 4xx alleen voor autorisatie/validatiefouten.
- **Een prikker telt als bezocht** zodra er ≥ 1 poging met `result = ok` is; `visited_at` = eerste `ok`.
  Latere pogingen blijven in het log (de gebruiker wil "alle pogingen"); ze veranderen de status niet.
- `maxAttempts` (optioneel) begrenst het aantal pogingen per prikker; daarna wordt een nieuwe poging nog wel
  gelogd, maar met `result = attempts_exhausted` (geen 4xx, en hij telt niet mee).

### `kako_app_positions` — positie-samples

`id`, `route_assignment_id`, `participant_id` (cascade), `user_id` (**cascadeOnDelete** — verwijdert de
gebruiker zijn account, dan verdwijnen z'n posities), `recorded_at` (ms, GPS-tijd), `lat/lon` `decimal(10,7)`
(≈ 1 cm; bewust niet nauwkeuriger bewaren dan de sensor kan), `accuracy`, `speed`, `heading`.
Unique `(participant_id, recorded_at)` (dedupe), index `(route_assignment_id, recorded_at)`.

Omvang: 15 s-interval × 4 uur ≈ 960 rijen/deelnemer/uitvoering; 500 deelnemers ≈ 0,5 M rijen — prima, mits de
bewaartermijn werkt (§9).

### `kako_app_events` — levenscyclus en toegangslog (alleen eigenaar/bewerker + de deelnemer zelf)

`id`, `route_assignment_id`, `participant_id`, `actor_user_id`, `type`, `data` (json), `created_at`.
Types: `joined`, `consent_given`, `consent_withdrawn`, `sharing_started`, `sharing_stopped`,
`mode_left`, `mode_resumed`, `window_set`, `window_extended`, `window_ended`, `data_exported`,
`positions_deleted`, `track_viewed`, `live_viewed` (gedempt: max 1 per kijker per 10 min).
De deelnemer ziet zijn eigen events — inclusief **wie zijn track heeft bekeken** (transparantie).

### `settings.appMode` (in `route_assignments.settings`, valt onder de productievergrendeling)

```jsonc
"appMode": {
  "tracking": { "mode": "optional",            // off | optional | required   (B1)
                "intervalS": 15, "retentionDays": 30 },                        // (B2)
  "checkin":  { "methods": ["manual","qr","nfc"],   // welke bewijzen toegestaan zijn
                "verification": "radius",           // record | radius          (B4)
                "radiusM": 30, "maxAttempts": null },
  "leaveAllowed": true,                         // deelnemer mag afgeschermde modus verlaten (gelogd)
  "tagVersion": 1                               // ophogen = alle QR/NFC-codes ongeldig (gelekt)
}
```

`settings.mode` (`paper|app|both`) blijft de hoofdschakelaar: uitnodigen kan alleen bij `app`/`both`.
`settings.restrictions` blijft zoals het is, met vastgelegde semantiek `true = toegestaan`.
Let op de naamgeving: `restrictions.locationSharing` betekent "mag de deelnemer z'n locatie met *anderen*
delen (kaart-live-locatie)"; het delen *met de instructeur* is `appMode.tracking` en staat daar los van.

## 5. API en autorisatie

Alles achter `auth:sanctum` (zelfde groep als de bestaande route-assignments). Throttles tegen misbruik.
"Deelnemer" = eigen `kako_app_participants`-rij met `status = accepted`; "Beheerder" = `canSeeParticipantData()`.

### Deelnemer

| Methode + pad | Doet | Notities |
|---|---|---|
| `POST /kako/join` `{code}` | claimt de plek | `throttle:10,1`; 422 ongeldige/verlopen code; 409 al geclaimd door een ander |
| `GET /kako/participations` | mijn deelnames: titel, mijn naam, venster, `appMode`-regels, `restrictions`, toestemmingsstatus | voedt de client-modusstore; gecached voor offline |
| `GET /route-assignments/{id}/participation` | **mijn payload** | zie hieronder |
| `POST /route-assignments/{id}/participation/consent` `{tracking: bool, version}` | toestemming voor positie-delen vastleggen/weigeren | weigeren bij `mode=optional` → status blijft `accepted`, geen tracking; bij `required` → `declined` (papier) |
| `DELETE /route-assignments/{id}/participation/consent` | intrekken; stopt delen direct | optioneel `?delete_data=1` wist eigen posities |
| `POST /route-assignments/{id}/checkins` | check-in-poging | idempotent op `client_uuid`; `throttle:30,1` |
| `GET /route-assignments/{id}/checkins/mine` | mijn pogingen + status per prikker | |
| `POST /route-assignments/{id}/positions` `{samples:[…≤200]}` | upload batch | antwoord altijd 200 met `{accepted, rejected, sharing:{active, reason}}`; `reason` ∈ `no_consent`, `withdrawn`, `outside_window`, `ended` → client stopt de watcher |
| `DELETE /route-assignments/{id}/participation/positions` | mijn posities wissen (AVG art. 17) | event `positions_deleted` |
| `GET /route-assignments/{id}/participation/my-data` | inzage-export (check-ins, posities, events) | AVG art. 15 |
| `POST /route-assignments/{id}/participation/mode` `{state: left|resumed}` | afgeschermde modus verlaten/hervatten | alleen als `leaveAllowed`; event; instructeur ziet het |

**Mijn payload** (`/participation`) bevat: eigen naam, `route_code`, volgorde, start/uiterlijk-binnen,
`appMode`-regels, venster, toestemming, en per route-deel de **been-lijst**: volgnummer, prikker-label,
omschrijving, en voor koersroutes `bearing_deg` + `distance_m` (hemelsbreed), voor padenroutes
`distance_m` langs de paden. Het bevat **niet**: coördinaten van prikkers (het startpunt hooguit),
prikpatronen (die zijn het bewijs — op de tang), routes of namen van anderen, `settings.posts`.
Dit is wat het papieren routeblad ook toont (`kakoRouteSheetPdf.js`); de app geeft niets méér.

### Beheerder (eigenaar / expliciete editor)

| Methode + pad | Doet |
|---|---|
| `GET /route-assignments/{id}/participants` | lijst: status, toestemming, venster, laatste positie + leeftijd, check-in-samenvatting, `has_map_access`; `invite_code` voor open uitnodigingen |
| `POST /route-assignments/{id}/participants/invites` `{participant_keys?}` | codes/links aanmaken of vernieuwen |
| `DELETE /route-assignments/{id}/participants/{key}` | intrekken (data blijft volgens bewaartermijn) |
| `PUT /route-assignments/{id}/app-mode/window` `{from, until, participant_keys?}` | venster zetten/verlengen — **ook in productie** (runtime-staat, niet vergrendeld; wel in het log) |
| `POST /route-assignments/{id}/app-mode/end` | nu beëindigen: venster sluit, delen stopt overal |
| `GET /route-assignments/{id}/live?since=` | laatste posities (polling 5–10 s; geen Reverb in prod) |
| `GET /route-assignments/{id}/checkins` | alle pogingen, filter op deelnemer/prikker |
| `GET /route-assignments/{id}/checkins/summary` | matrix deelnemer × prikker (bezocht / aantal pogingen) |
| `GET /route-assignments/{id}/positions?participant=&from=&to=&step=` | track van één deelnemer (event `track_viewed`) |
| `GET /route-assignments/{id}/together` | "samen gelopen" (fase 3, §8) |
| `GET /route-assignments/{id}/participant-events` | levenscyclus + toegangslog |

Zichtbaar *niet*-bestaan: een niet-gerechtigde krijgt op beheerders-endpoints 403 (bestaat al zo bij de
rest van de opdracht); deelnemer-endpoints geven 404 als je geen deelnemer van die opdracht bent.

## 6. Check-in: handmatig, QR, NFC

**Handmatig** (fase 1): knop "Ik ben bij deze prikker". De app voegt een verse GPS-fix toe; de server rekent
de afstand tot de prikker uit. Bij `verification = radius` is de poging `ok` als
`afstand − min(accuracy, 25 m) ≤ radiusM` (standaard 30 m — onder bomen is GPS vaak 10–20 m naast),
anders `too_far`; bij `record` is het altijd `ok` en staat `within_radius` als vlag in het log. Geen fix →
`no_position` (de poging telt niet, maar staat in het log). Opnieuw proberen mag zo vaak als gewenst (of tot `maxAttempts`).

**QR** (fase 4): per prikker een code `…/kako/p/<assignmentId>/<postId>/<token>` met
`token = base64url(HMAC-SHA256(app.key, "kako:<assignmentId>:<postId>:<tagVersion>"))` (16 bytes). De
instructeur print de codes (A4-stickers, `qrcode` is al dependency), de deelnemer scant in de app
(getUserMedia + `jsQR`, camera-rechten zijn al gedeclareerd) of met de systeemcamera (`milmap://`-schema
bestaat al). Een geldig token bewijst *aanwezigheid bij de code* en maakt GPS-straal optioneel
(`result = ok`, afstand blijft gelogd); een ongeldig token → `wrong_code`.
Eerlijk: een QR-foto kan worden doorgestuurd. Combineer met `verification = radius` voor sterker bewijs.
Gelekt? `tagVersion` ophogen maakt alle gedrukte codes ongeldig.

**NFC** (fase 4, apart traject): zelfde URL als NDEF-URI op NTAG213-stickers (±€0,50 per stuk, schrijfbeveiligd).
- *Lezen zonder plugin* (goedkoopste route): iPhone XS+ leest NDEF-URL's op de achtergrond en opent
  **universal links**; Android opent `milmap://` direct (schema-filter bestaat). Dat vergt: AASA op
  `app.milmap.nl` uitbreiden met `/kako/p/*` (serverkant), Android-manifest `pathPrefix="/kako/"` met `autoVerify`
  (nieuwe build), en `deepLinks.js` dynamisch pad-parsen (nu een statische tabel).
- *In-app lezen/schrijven* (instructeur schrijft tags, deelnemer scant in de app): Capacitor-NFC-plugin
  (kandidaten o.a. `@capawesome-team/capacitor-nfc`, `@exxili/capacitor-nfc` — **compatibiliteit met Capacitor 7 en licentie
  nog te verifiëren**), iOS-entitlement `com.apple.developer.nfc.readersession.formats` + App-ID-capability
  "NFC Tag Reading" + `NFCReaderUsageDescription` (nieuw provisioning profile, App Store-review let hierop), Android `NFC`-permissie.
- NFC werkt **alleen native**; op web valt de UI terug op QR. Iedereen zonder NFC-telefoon kan QR/handmatig.

## 7. Afschermen — wat server doet, wat de app doet, wat niemand kan

`restrictions[key] === false` binnen het venster betekent: geblokkeerd.

**Server (hard, binnen wat de server beheert)**
1. *Geen verklikkers*: de deelnemer-API geeft alleen de eigen route (§5); geen toegang tot de opdracht of kaart.
2. *423 `kako_restricted`* (middleware `KakoRestrictions:<key>`, fase 2) op endpoints die de instructeur heeft
   uitgezet, alleen als de gebruiker een actieve, beperkende deelname heeft: `locationSharing`
   (`user-locations/*`), `editing` (waypoint/route/kaart-schrijfroutes), `navigation` en `tools`
   (zoek/geocode/routing/hoogte-proxy's die wij zelf serveren), `sidebar`/`routemap` (lijst-endpoints).
   Kost per verzoek één cache-lookup (`kako:restrict:<userId>`, 60 s, geïnvalideerd bij venster/regel-wijziging).
3. *Positie-grens*: posities worden alleen geaccepteerd met toestemming én binnen het venster (§9).

**Client (UI, fase 2)**
- Modusstore uit `GET /kako/participations` (offline gecached, met serverklok-offset zodat terugzetten van de
  klok niet werkt zolang er sinds de laatste sync < 12 u verstreken is).
- Router-guard: in beperkte modus alleen `/kako/deelname/:id`, account/uitloggen en noodinfo; de rest redirect.
  Verbergt navigatie, zijbalk, kompas, MGRS, routekaart-technieken. Siri/deep links lopen via dezelfde router.
- Knop "Afgeschermde modus verlaten" (als `leaveAllowed`): logt `mode_left`, de instructeur ziet het direct.

**Eerlijk over de grenzen** (komt ook zo in de app en op het instructeursscherm te staan)
- MilMap kan een andere kaart-app, een tweede toestel of een papieren kaart niet tegenhouden — bij een KAKO
  *is* de papieren kaart juist het examen. Wat de beperking doet: de app-hulpmiddelen weghalen en
  afwijkend gedrag **zichtbaar** maken (modus verlaten, geen positie meer, uitloggen, ander account).
- Beperkingen hangen aan het **account**: uitloggen of een tweede account omzeilt ze. Mitigatie: de instructeur
  ziet "geen signaal sinds 14:07" en wie z'n deelname niet actief heeft.
- Apparaatklok en GPS zijn te manipuleren (nep-locatie-apps; Android levert soms een `isMock`-vlag — opnemen als
  plugin het geeft). Posities zijn **aanwijzingen, geen bewijs**.
- De enige echt waterdichte afscherming is MDM *single app mode* / Guided Access op door de organisatie beheerde
  toestellen (Connect-apparaten). Dat ligt buiten de app; hier alleen als optie benoemen.
- **Voor een formeel examen blijft toezicht door een instructeur nodig.**

## 8. "Samen gelopen"-analyse (fase 3)

Berekening op de server (`KakoTogetherService`), op aanvraag, resultaat gecached op `(opdracht, laatste sample-id)`.

1. **Per deelnemer, binnen het venster, naar een vast 30 s-raster**: positie door lineair interpoleren tussen
   buursamples; ontbreekt data > 120 s → leeg (geen gok).
2. **Per paar (en per 3+ groep)**: afstand per tijdvak (equirectangular, genoeg onder 10 km).
   *Samen* als `d ≤ 30 m + ½(min(acc_a,40)+min(acc_b,40))`, begrensd op 70 m.
3. **Uitsluitingen** (anders is alles "samen" bij start en aan het eind): beide binnen 75 m van start/finish;
   en samen bij een **prikker** (≤ 40 m) telt niet mee — ontmoetingen bij een post zijn normaal.
4. **Segmenten**: opeenvolgende "samen"-tijdvakken (gaten ≤ 2 tijdvakken gedicht), minimaal 3 min.
   Per segment: leden, `van–tot`, duur, gemiddelde/min/max tussenafstand, samen afgelegde afstand, soort
   (`lopend` vs `stilstaand samen` — stilstaand samen weg van een post is juist interessant), nabij welke prikker.
5. **Tussenafstanden-tijdlijn** per paar: afstand per 2-minutenvak (gem./min/max) → de matrix "wie liep wanneer hoe ver van wie".
   Antwoord: `{pairs:[{a,b,segments:[…],series:[{from,to,avg,min,max}]}], groups:[…]}`.
6. Schaal: 20 deelnemers × 480 vakken ≈ 91 k afstandsberekeningen — niets. Bij honderden deelnemers: spatial hash
   (cellen van 100 m per vak) i.p.v. alle paren.

Opmerkingen: dezelfde route met 10 min tussenstart betekent dat deelnemers elkaars pad volgen — vandaar de
30 m-grens *op hetzelfde tijdstip*. Vals-positieven (GPS onder bomen, één wegsplitsing) zijn mogelijk: de UI
zegt "**mogelijk** samen gelopen", toont de ruwe tijdlijn en laat de beoordeling aan de instructeur.
Het doel (controle op samen lopen) staat **expliciet in de toestemmingstekst** (§9) — geen verborgen verwerking.

## 9. Privacy / AVG — de bepalende afweging

*(Dit is een technisch-organisatorisch ontwerp, geen juridisch advies; laat de punten met ⚖ toetsen.)*

**Wat is het.** Locatiegegevens van personen, systematisch en continu verzameld, gedeeld met een derde (de
instructeur), plus een analyse van onderlinge contacten. Geen bijzondere categorie, wel gevoelig; bij
militairen ook operationeel gevoelig (OPSEC). Posities zijn server-leesbaar (net als missie-/kaartdata, zie
chat-E2EE-notitie); end-to-end versleutelen kan niet zonder de live-kaart en de analyse op te geven — bewust niet nagestreefd.

**Rollen** ⚖. De instructeur/organisatie bepaalt doel en middelen → *verwerkingsverantwoordelijke*; MilMap levert
de dienst → *verwerker* (art. 28: verwerkersovereenkomst; privacyverklaring op legal.milmap.nl uitbreiden —
het systeem heeft hiervoor `legal_documents`). Een DPIA (art. 35) is aannemelijk: systematisch volgen van personen
(locatiegegevens staan op de lijst van de AP). Voor een school: ook de leeftijdsgrens (<16: toestemming ouder, art. 8) ⚖.

**Grondslag — het knelpunt.** Toestemming (art. 6.1.a) moet *vrij* zijn. In een examen-, werk- of
militaire context is er een machtsverhouding en is toestemming zelden vrij. Daarom:
- *Optioneel* delen met **papier-alternatief zonder nadeel** → toestemming houdbaar. (Aanbevolen; B1.)
- *Verplicht* delen → dan is toestemming de verkeerde grondslag; de organisatie moet zelf een andere grondslag
  (gerechtvaardigd belang: veiligheid van deelnemers bij nachtelijke tochten) onderbouwen ⚖. De app ondersteunt
  het (`required`), maar toont dan "kies: delen óf papier" en verbergt dat niet.

**Maatregelen (alles hieronder zit in het ontwerp, niet "later")**

| Eis | Invulling |
|---|---|
| Transparantie | Toestemmingsscherm vóór de OS-prompt (patroon `bgLocationDisclosure`): **wat** (positie ±elke 15 s, check-ins), **wie** (namen van eigenaar + bewerkers), **wanneer** (alleen start–uiterlijk binnen), **waarom** (veiligheid, beoordeling, controle op samen lopen), **hoe lang** (bewaartermijn), **rechten**. Versienummer `consent_version`; nieuwe tekst = nieuwe toestemming. |
| Aparte, expliciete toestemming | Eigen schakelaar, standaard uit, niet gebundeld met "deelnemen"/algemene voorwaarden. |
| Doelbinding | Posities uitsluitend voor live-overzicht, veiligheid en de "samen"-analyse; niet voor analytics (`app_events` krijgt nooit coördinaten), niet voor andere opdrachten. |
| Beperking in tijd | Server accepteert posities alleen binnen `window_from..window_until` (max 12 u) en weigert daarbuiten, ongeacht wat de app doet. Late upload (offline) mag tot 48 u na afloop, alleen voor samples *binnen* het venster. |
| Zichtbaarheid voor de deelnemer | Permanente indicator "positie wordt gedeeld met …" met knop "Stop delen"; gestopt = instructeur ziet "gestopt door deelnemer 14:07" (geen blokkade, wel zichtbaar). |
| Intrekken | `DELETE …/consent`: stopt direct; eerder gedeelde posities blijven tot de bewaartermijn tenzij de deelnemer wissen vraagt. |
| Inzage / wissen | `my-data` (export) en `DELETE positions` voor de deelnemer zelf — zonder tussenkomst van de instructeur. |
| Bewaartermijn | Ruwe posities: `retentionDays` na afloop van het venster (standaard 30 d, 7–90 instelbaar; B2), dagelijks gewist door `kako:purge-positions`; de afgeleide "samen"-segmenten idem (ze zijn persoonsgegevens over onderlinge relaties). Check-ins/events: zolang de opdracht bestaat (examenadministratie), na verwijderen opdracht → weg (cascade). |
| Account weg | `positions.user_id` cascade → posities weg; deelnemer-rij blijft als naam-momentopname zonder account; check-ins verliezen `user_id`. |
| Toegang | Alleen eigenaar + expliciete editor (B3); iedere keer dat een track of de export wordt geopend komt er een event dat de deelnemer in zijn inzage ziet. |
| Beveiliging | TLS, achter Sanctum, geen coördinaten in logs/analytics; throttles; `invite_code` kort geldig en eenmalig. |
| Winkelregels | Achtergrondlocatie en delen met derden: Apple 5.1.1/5.1.2 + Google Play-"prominent disclosure" — hergebruik de bestaande flow; privacy-label/Data-safety bijwerken zodra we posities naar de instructeur sturen ⚖. |

**Beslispunten — door de gebruiker bevestigd op 2026-10-31 (alle vier de aanbevolen waarden):**

- **B1 Positie delen: verplicht of optioneel?** → *Per opdracht door de instructeur te kiezen (uit / optioneel / verplicht), standaard optioneel met papier-alternatief.*
- **B2 Bewaartermijn ruwe posities** → *30 dagen na afloop, instelbaar 7–90.*
- **B3 Wie ziet posities en logs** → *Eigenaar + expliciet geaccepteerde bewerkers (niet: impliciete kaartbewerkers, niet: kijkers).*
- **B4 Check-in: afstand afdwingen of alleen vastleggen?** → *Per opdracht kiezen; standaard afdwingen binnen 30 m, QR/NFC-token bewijst aanwezigheid zonder GPS.*

**Standaardkeuzes** (niet expliciet gevraagd; gelden tenzij de gebruiker anders zegt): uitnodigen via persoonlijke code/QR (S1);
deelnemer mag afgeschermde modus verlaten, gelogd (S2); NFC eerst als URL-tags zonder plugin, native lezen later (S3);
deelnemen kan met een gratis account (S4); posities niet E2E-versleuteld (S5); instructeur ziet "geen signaal sinds…" (S6);
geen accu-%, geen achtergrondtaak in fase 1 (S7).

## 10. Offline, batterij, netwerk

- **Check-ins**: via de bestaande outbox (`QUEUEABLE` + regex `…/route-assignments/<id>/checkins`). Optimistisch
  antwoord → de rij toont "wacht op verbinding"; bij herhaling bepaalt de server het resultaat uit de *opgeslagen*
  positie en tijd (de afstand rekent hij uit de meegestuurde fix, niet uit de ontvangsttijd). `client_uuid` voorkomt dubbelen.
- **Posities**: eigen ringbuffer (max. 1000 samples ≈ 100 KB, oudste valt weg), batch-upload elke 60 s / 20 samples /
  bij check-in en app-voorgrond. Niet in de outbox: volume, "N wijzigingen wachten"-ruis, en bij intrekken moet de buffer meteen leeg.
- **Interval**: 15 s bij beweging, 60 s stilstaand, 60 s bij laag accuniveau; `enableHighAccuracy` alleen tijdens beweging.
  Fase 1 is voorgrond (scherm aan); fase 5 achtergrond via `background-geolocation` (foreground service-melding
  op Android). Accuverbruik meten voor we het beloven.
- **Tijd**: samples dragen de GPS-tijd (`position.timestamp`), niet de apparaatklok.
- **Connect-apparaten**: beleid `share_location=false` blokkeert positie-upload; check-in mag niet onder het generieke
  `edit`-recht vallen (`EnforceConnectManagement` — gedeeld bestand, in fase 3 met de sibling-sessie afstemmen).

## 11. Fasering

| Fase | Inhoud | Klaar als |
|---|---|---|
| **1 (nu)** | 4 tabellen + modellen; join-code, toestemming, venster (API), mijn payload, check-in (handmatig, afstandscontrole, pogingen), positie-upload met venster/toestemming-poort, live/checkins/positions-lezen voor beheerders, `kako:purge-positions`; Bruno `kako/5x-…`, PHPUnit; frontend `KakoDeelname.vue` (join, toestemming, mijn route, check-in via outbox, voorgrond-positiedelen) | Bruno + PHPUnit groen, deelnemer-pagina lokaal doorlopen |
| 2 *(deels gebouwd, zie §14)* | Instructeur-tab "Deelname" in `KompasOpdracht.vue` (codes, venster start/stop, live kaart, check-in-matrix, log); afscherming server (423-middleware) + client (modusstore + router-guard); Connect-apparaat koppelen met groepen; **score uit check-ins** (afgeleide `visited` per prikker als voorstel voor `participants[].result.posts`, instructeur bevestigt); **meekijk-/preview-modus** (instructeur ziet de deelnemer-cockpit van een gekozen deelnemer, alleen-lezen) | restrictions werkelijk afgedwongen binnen het venster |
| 3 | "Samen gelopen" + export (CSV/PDF), toegangslog in inzage, Connect-compat (`EnforceConnectManagement`) | analyse op testdata correct |
| 4 | QR (print + scan), NFC (URL-tags, daarna native plugin), universal links `/kako/p/*` | QR/NFC-check-in op echte toestellen |
| 5 | Achtergrond-GPS, adaptief interval, "geen signaal"-alarm, noodknop, accu-meting | veldtest |

Open technische punten die we onderweg tegenkomen: Android `isMock`-vlag; iOS-WKWebView `localStorage`-purging
onder opslagdruk (ringbuffer → eventueel IndexedDB, zie `indexedDbRecovery.js`); AASA/manifest-uitbreiding
en een nieuwe store-build voor deep links; i18n (nu NL, de app kent 12 talen — deelnemerstekst minstens nl+en).

## 12. Fase 1 — wat er nu staat (2026-10-31)

**Backend** (`milmap-backend/`)

| Bestand | Wat |
|---|---|
| `database/migrations/2026_10_31_120000_create_kako_app_mode_tables.php` (+ `…130000_add_deadline_to_kako_app_checkins.php`) | `kako_app_participants`, `_checkins`, `_positions`, `_events` (§4); `deadline_at` per check-in |
| `app/Models/KakoApp{Participant,Checkin,Position,Event}.php` | modellen; `Participant::inWindow()` / `hasConsent()` |
| `app/Support/KakoAppRules.php` | pure regels: instellingen + standaardwaarden, afstand/koers, QR/NFC-token, routecode → delen, benen zonder coördinaten |
| `app/Http/Controllers/KakoAppController.php` | alle endpoints uit §5 behalve `together` (fase 3) |
| `routes/api.php` (blok na `route-assignments/{id}`), `routes/console.php` (1 regel) | routes + dagelijkse `kako:purge-positions` |
| `app/Console/Commands/KakoPurgePositions.php` | bewaartermijn: posities + check-in-coördinaten + laatste-positie-cache wissen (`--dry-run`) |
| `tests/Feature/KakoAppModeTest.php` (22), `tests/Unit/KakoAppRulesTest.php` (7) | autorisatie, join, check-in-uitkomsten, venster, toestemming, wissen, bewaartermijn, account verwijderen |
| `milmap-api-tests/kako/70…100-app-*.bru` (31 requests) | volledige flow eigenaar ↔ deelnemer; `./run-local.sh` is groen (127/127) |

**Frontend** (`MilMap-Frontend/`)

| Bestand | Wat |
|---|---|
| `src/views/maps/KakoDeelname.vue` | `/kako/deelnemen/:code?` (code controleren → meedoen), `/kako/deelname` (mijn opdrachten), `/kako/deelname/:id` (cockpit: tijden, toestemming, route per deel, check-in per prikker, pogingenlog, stoppen/wissen, mijn gegevens) |
| `src/services/KakoParticipationService.js` | API-wrapper |
| `src/services/kakoPositionSharing.js` | watcher + adaptief interval + buffer (localStorage, max 1000) + batch-upload; stopt zodra de server `sharing.active=false` zegt |
| `src/router/index.js` (2 routes), `src/services/outbox.js` (regex + label `checkin`) | kleine atomaire edits; check-ins gaan via de bestaande offline-outbox |
| `tests/kako-app-modus.test.mjs` (11) | interval, buffer, flush/stop-gedrag, outbox-scope |

**Bewust (nog) niet in fase 1**: instructeur-UI (alles gaat nu via de API/Bruno; `settings.appMode` heeft nog geen scherm), afdwingen van `restrictions` (server 423 + client-guard = fase 2), "samen gelopen" (fase 3), QR/NFC (fase 4; de server valideert tokens al en `GET …/tag-tokens` levert ze), achtergrond-GPS (fase 5: delen werkt nu alleen met de pagina open), i18n (nu NL), `EnforceConnectManagement`-aansluiting en `EnsureNotViewOnly`-allowlist (e-mail-uitgenodigde view-only accounts mogen nog niet schrijven; fase 2 met de e-mail-uitnodiging).

**Afwijkingen van het ontwerp tijdens het bouwen** (de code is leidend, dit staat hier zodat het document niet liegt)
- Wie bij "verplicht" voor papier kiest (`declined`) kan toestemming nog herzien (`POST …/participation/consent`); check-ins/posities blijven dan dicht.
- Weigeren bij "optioneel" wordt vastgelegd als *geen toestemming* (`consent_withdrawn_at` gezet), zodat de app niet blijft vragen. Opnieuw toestemming geven wist die vlag.
- `live_viewed` (de instructeur opent de live-kaart) is een groepsbrede gebeurtenis zonder deelnemer; hij verschijnt in ieders `my-data`.
- De check-in-uitkomst `ok` voor een prikker telt over beide route-delen heen één keer (zelfde prikker in kompas- én kaartloop = één punt, zoals bij de score); het wisselpunt tussen de delen heeft rol `post` en label "Wissel", alleen de eindstreep is `finish`.
- Check-in-coördinaten zijn onderdeel van de check-in zelf (zonder tracking-toestemming toegestaan) en worden na de bewaartermijn genuld; afstand en uitkomst blijven.

**Lokaal proberen**: de dev-backend op :8000 (sqlite `/tmp/milmap-dev-terreinen.sqlite`) heeft de vier tabellen; testgegevens (instructeur + deelnemer + opdracht) en de inloggegevens staan in de scratchpad van deze sessie (`kako-dev.env`, niet in de repo).

## 13. Toetscontext: opleidingsreglement EMVMARNS (navigatietoets) — bewijskracht

*(Aangeleverd 2026-10-31 door de sessie "Kako UI vernieuwing", uit het reglement dat de gebruiker deelde. De puntentelling en de open 3-benige kompasloop zijn daar nog niet gebouwd en door de gebruiker niet bevestigd.)*

**Wat het reglement zegt, voor zover het de app raakt**
- Een toetsmoment = een 3-benige kompasloop met 3 prikkers + een coördinatenloop (= onze kaartloop) met 5 prikkers, ± 5 km, binnen de gestelde tijd; 5 toetsmomenten, 3 moeten positief zijn.
- Kompasloop: alle 3 prikkers vinden en juist registreren op de controlestrook. Coördinatenloop: minimaal 4 van 5 juist (binnen de tijd) = 55 punten; alles goed en op tijd = 100.
- **Regel 5: communicatie tussen lerenden tijdens een toetsmoment is niet toegestaan.** De "samen gelopen"-analyse (§8) en de afscherming (§7) zijn dus inhoudelijk belangrijk; het log is mogelijk bewijs bij een examenzaak.
- Tijd: max. 180 min overdag / 300 min bij duister bij ≤ 6800 m, naar verhouding naar beneden afgerond op hele minuten (`settings.timeRule` mode `formula`, `K.allowedSeconds()` in `utils/kompasOpdracht.js`), plus `timeRule.extraMin` extra tijd per deelnemer.

**Gevolgen voor het ontwerp**

| Onderwerp | Besluit / stand |
|---|---|
| Venster = cut off | Fase 2 zet `window_from` = starttijd uit de planning en `window_until` = start + `K.allowedSeconds(settings, daypart, afstand van de routecombinatie, withExtra=true)`; dat past binnen de 12-uurs-grens. |
| "Te laat" herkenbaar | **Gebouwd.** Elke check-in bewaart `deadline_at` (de cut off op dat moment) en toont `late_s` (seconden na de cut off, anders `null`). Een poging na de cut off krijgt `outside_window`, telt dus niet als bezocht ("binnen de tijd"), maar is als te laat te zien. Later verlengen of inkorten van het venster herschrijft dit niet. |
| Controlestrook blijft het bewijs | Voor de score van het reglement geldt de geprikte controlestrook. De app-check-in is **aanvullend bewijs** (wie, wanneer, waar) en een voorstel voor de score, geen vervanging. |
| Log controleerbaar en onveranderlijk voor de deelnemer | Nu al zo: er is geen endpoint waarmee een deelnemer (of de instructeur) een check-in of gebeurtenis wijzigt of wist; een opdracht in productie is niet te verwijderen (423); `received_at` is serverklok, `attempted_at` is GPS-tijd binnen grenzen. **Gat:** de deelnemer kan z'n eigen posities wissen (AVG art. 17) en toestemming intrekken. Zie beslispunt B5. |
| Aantoonbaar onveranderd | Fase 3: per deelnemer een hash-keten over check-ins (elke rij bewaart de hash van de vorige + eigen inhoud) en een export (CSV/PDF) met eind-hash, zodat achteraf te zien is of er iets is aangepast. |
| "Samen gelopen" als bewijs | Blijft een aanwijzing, nooit een automatisch oordeel (§8). Voor een examenzaak: bewaar bij elk resultaat de parameters (straal, minimumduur, softwareversie) zodat het reproduceerbaar is, en leg het oordeel van de instructeur (bevestigd/afgewezen + toelichting) los van de ruwe data vast; de deelnemer ziet dit in z'n inzage. |
| Score volgens het reglement | **Niet gebouwd en niet bevestigd.** `checkins/summary` levert per deelnemer × prikker `visited` (alleen `ok` binnen de tijd); fase 2 kan daaruit een voorstel voor `participants[].result.posts` maken (kompasloop 3/3; coördinatenloop ≥ 4/5 = 55, alles + op tijd = 100) dat de instructeur bevestigt. |
| Open 3-benige kompasloop (zonder terugweg) | Geen aanpassing nodig in de app-modus: de beenlijst volgt `route_maps.locations`; zonder eindstreep op de start vraagt de app ook geen `finish`-check-in en `posts_total` klopt vanzelf. |

**Beslispunt B5 (open, voor de gebruiker):** mag een deelnemer bij type *Examen* zijn eigen posities direct wissen, of is dat een **verzoek** dat de organisatie (verwerkingsverantwoordelijke) afhandelt zolang de beoordeling/bewaartermijn loopt? Voorstel: bij *Training* direct wissen (zoals nu); bij *Examen* een wis-verzoek met zichtbare status voor de deelnemer en een beslissing door de eigenaar (vastgelegd in de events), en intrekken van toestemming stopt delen voor de toekomst en blijft als zichtbaar gat in het log staan. De afweging tussen recht op wissen en bewijskracht is juridisch ⚖ en aan de organisatie; de app moet beide kunnen.

## 14. Fase 2 (deels): instructeursscherm, meekijken, score uit check-ins (2026-10-31)

Gevraagd door de gebruiker (via de sessies "Routeselectie in deelnemersoverzicht" en "Kako UI vernieuwing"); lokaal gebouwd en getest, niet gedeployed.

| Onderdeel | Stand |
|---|---|
| Tab **Deelname** in `KompasOpdracht.vue` (groep Uitvoeren, URL `/kompas-opdracht/<kaart>/deelname?assignment=<id>`) | `components/kako/KakoDeelnameTab.vue`: voorwaarden (`settings.appMode`: positie uit/optioneel/verplicht, check-in afdwingen/vastleggen + straal, bewaartermijn, afgeschermde modus verlaten; vergrendeld in productie), deelnemerskaarten (status, toestemming, laatste positie als MGRS + leeftijd, check-ins, uitnodigingscode + link kopiëren, intrekken), **venster uit de planning** (per deelnemer starttijd → cut off = uiterlijk binnen van het gekozen dagdeel; groepeert per starttijd; ook "Beëindig nu"). Pollt elke 15 s; 403 → uitleg dat alleen eigenaar/uitgenodigde bewerkers dit zien. |
| **Meekijken** | `GET /route-assignments/{id}/participants/{key}/preview` (alleen beheerder; ook voor nog niet aangesloten deelnemers; volgt altijd de HUIDIGE `route_code`; schrijft niets). In de tab opent "Meekijken" `KakoDeelname.vue` als alleen-lezen dialoog (`preview-key`, `embedded`): geen toestemming, geen check-in-knoppen, geen positie delen. |
| **Score uit check-ins** | `utils/kakoAppScore.js` zet een geslaagde check-in (binnen de tijd) op `participants[i].result.posts[prikker] = 1` + `result.auto[prikker]` (tijdstip); het oordeel van de instructeur wint (0, ½, ✓ worden nooit overschreven), start/finish/wissel tellen niet. De builder bewaart dit zelf via de bestaande autosave; in de Score-tab staat een "app"-label bij automatisch gezette prikkers. Alleen prikkers; `finish_time` wordt (nog) niet automatisch gezet. |
| Route wisselt in productie | Peer-wijziging (andere route kiezen in het overzicht, `lockedChange` negeert `route_code`): preview en check-in volgen de huidige code; een check-in op een prikker van de oude route wordt `not_on_route` (getest). |

**Nog open in fase 2**: afdwingen van `settings.restrictions` (server-423-middleware + client-modusstore/router-guard), live kaartweergave van de posities (nu een lijst met MGRS), koppelen van Connect-apparaten/groepen aan app-deelnemers, `EnforceConnectManagement`/`EnsureNotViewOnly`, automatisch `finish_time`, e-mail-uitnodigingen. Opmerking: elke autosave die door een check-in-merge ontstaat komt in het bewerkingslog als "beoordeling — geprikte posten bijgewerkt" op naam van de instructeur wiens scherm openstond.

