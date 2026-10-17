# Connect-apparaatlogin op iOS en Android

Deze pagina vervangt de oorspronkelijke oplevernotitie van 16 oktober. De actuele migraties, organisatiepolicy, offline toegang, Stripe-inrichting en releasecontroles staan in [Connect management, seat orders and Android Enterprise](connect-management.md).

## Kies de juiste configuratie

| Platform/modus | Managed app configuration | Authenticatie |
| --- | --- | --- |
| Android Enterprise | `device_binding`: het unieke geheim uit de binding-API | Een binding is altijd verplicht, ongeacht de iOS-instelling van de organisatie. |
| iOS, `binding` | `device_binding`: het unieke geheim; optioneel `device_id` voor metadata/huisstijl | De binding geeft toegang. Alleen een geregistreerd UDID is onvoldoende. |
| iOS, `udid` | `device_id`: het volledige geregistreerde UDID uit de MDM-inventaris | Het geregistreerde ID geeft toegang zonder afgeleverd geheim. Een UDID is niet geheim. |

De huidige organisatiestandaard is **`udid` voor iOS**. Migratie `2026_10_16_220000_default_device_login_udid` zet ook bestaande organisaties op die modus. Kies na die migratie in Connect expliciet **Device binding** voor organisaties die een geheim per apparaat vereisen. Deze keuze wijzigt Android niet. Een aanwezige `device_binding` heeft in de app voorrang boven `device_id`.

De app vraagt geen echt Apple-UDID aan iOS. Lever voor iOS het volledige ID uit de betrouwbare MDM-inventaris; IDFV/Capacitor Device.getId is niet hetzelfde. Android gebruikt een stabiel inventaris-ID uit de MDM, geen geclaimd Android-ID als authenticatiebewijs.

## Apparaat registreren en binding afleveren

1. Pas vóór gebruik de volledige migratiereeks uit [connect-management.md](connect-management.md#rollout) toe via het normale releaseproces. Alleen de oorspronkelijke bindingmigratie uitvoeren is onvoldoende.
2. Registreer het apparaat in de juiste organisatie via de allow-list of `POST /api/v1/connect/mdm/devices`, met het organisatie-MDM-token als `Authorization: Bearer …`. Stuur `udid`, `name` en expliciet `platform: ios` of `platform: android`. De legacy veldnaam `udid` geldt voor beide platformen; toegestane IDs bevatten 8–64 letters, cijfers of koppeltekens.
3. Voor Android en iOS in bindingmodus: laat de vertrouwde MDM-integratie `POST /api/v1/connect/mdm/devices/{udid}/binding` uitvoeren met hetzelfde organisatietoken. De respons geeft eenmaal een willekeurige `device_binding` van 64 alfanumerieke tekens terug. De server bewaart alleen de SHA-256-hash. Een nieuwe aanvraag roteert het geheim en trekt bestaande sessies in.
4. Lever het geheim uitsluitend aan het bijbehorende apparaat als managed app configuration voor `nl.milmap.app`. Android leest dit via `RestrictionsManager`; iOS via `com.apple.configuration.managed`. Gebruik op iOS in UDID-modus alleen de juiste `device_id` wanneer je zonder binding wilt inloggen.
5. Installeer een native appbuild met de geregistreerde `MilMapManagedConfig`-plugin. Android heeft ook het schema `app_restrictions.xml` nodig. Een JavaScript/OTA-update alleen kan de native plugin niet toevoegen. Richt de afzonderlijke aflevering van de bindings zelf in de MDM in; registratie in de API levert de configuratie nog niet aan het apparaat af.

Het organisatie-MDM-token blijft uitsluitend in de serverintegratie. Geef elk apparaat een eigen binding en deel die niet via één statische organisatieconfiguratie. Gebruik HTTPS en plaats het geheim niet in URL’s of logs. Een binding is een bearercredential en geen hardwareattestatie.

## Sessies, intrekken en behouden werk

Geconfigureerde apparaten gebruiken de Connect-weergave op Home met één knop **Inloggen**. De gewone auth-routes verwijzen uitgelogde apparaten met herkende managed configuratie naar Home. Normale accounts behouden hun gewone login. De server controleert de apparaatkoppeling, actieve organisatie/licentie en het lidmaatschap; gewone wachtwoordlogin of een ongebonden token geeft een device-account geen toegang.

Voor Android en iOS in bindingmodus vergelijkt de app bij een koude start verse native configuratie met de tokengebonden fingerprint vóór het vrijgeven van lokaal werk. De controle wordt op focus/hervatten herhaald; Android luistert ook naar configuratiewijzigingen. Een ontbrekende of gewijzigde binding of ontbrekende fingerprint blokkeert de sessie. Het geheim wordt niet in JavaScript-opslag bewaard. De aparte iOS-UDID-modus gebruikt deze geheime-bindingcontrole niet.

Verwijder bij uitschrijving het apparaat via `DELETE /api/v1/connect/mdm/devices/{udid}` en verwijder de managed configuratie via de MDM. De API trekt de binding en sessies in; een offline apparaat krijgt serverwijzigingen pas bij verbinding of bij het aflopen van de eerder afgegeven offline toegang. Gebruik de MDM voor het wissen van appdata of het werkprofiel wanneer dat nodig is.

Het verwijderen van een allow-listitem verwijdert geen device-account of organisatiegegevens. Bestaande accounts worden bij nieuwe provisioning niet automatisch overgenomen; een accountbotsing kan HTTP 409 geven en vereist beheercontrole. Er wordt geen accountnaam meer afgeleid van alleen het prefix vóór het eerste koppelteken. Voor overdracht van behouden werk gelden de organisatie- en eigendomsregels uit [connect-management.md](connect-management.md#ownership-and-handover).

## Verificatie vóór vrijgave

Backend- en UI-tests gebruiken geïsoleerde data en gemockte betaalverwerking; Android Java-compilatie is geslaagd. Er is geen fysieke iOS/Android-apparaattest, echte MDM-configuratiebezorging of echte betaling uitgevoerd voor deze wijziging. Productie- en migratiestatus volgen niet uit deze tests.

Controleer vóór klantgebruik op echte beheerde apparaten beide iOS-modi en Android: configuratiebezorging, Home/login, koude start en hervatten offline, bindingrotatie/verwijdering, intrekken van toegang, policyverval en tenantisolatie. De volledige releasecontroles staan in [connect-management.md](connect-management.md#verification-and-release-checks).
