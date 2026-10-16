# Connect-apparaatlogin — 16 oktober 2026

Geïmplementeerd in het bestaande project onder `/Applications/XAMPP/xamppfiles/htdocs/milmap`.

## Gereed

- Geconfigureerde Connect-iPads gaan vanaf `/login`, `/register`, `/password-forgot`, `/password-reset` en (uitgelogd) `/verify-email` naar `/` met `Home.vue`.
- Home toont voor deze apparaten dezelfde Connect-loginweergave: één knop **Inloggen**, zonder e-mail-, wachtwoord-, registratie- of social-loginformulier. Apparaten zonder binding houden de bestaande schermen. Bestaande sessies blijven de bestaande routering volgen; admin-login staat los hiervan.
- iOS leest `device_binding` uit de bestaande native `MilMapManagedConfig`-plugin. Er wordt geen echte UDID uit iOS opgevraagd.
- Alleen het MDM-servertoken van de betreffende organisatie kan voor een vooraf geregistreerd apparaat een individuele binding laten uitgeven. De server bewaart alleen de SHA-256-hash.
- De server maakt een apart device-useraccount en actief Connect-lid aan. E-mailadres: hoofdletterprefix vóór het eerste koppelteken van het geregistreerde volledige UDID, bijvoorbeeld `18D2D99B@connect.milmap.nl`.
- Bestaande accounts of botsende prefixes worden niet overgenomen: provisioning weigert met 409. Het volledige geregistreerde UDID, de organisatie en de userkoppeling komen uit de database, nooit uit het client-emailveld of een UDID-header.
- Device-accounts kunnen niet via gewone wachtwoord-/codelogin of een ongebonden Sanctum-token langs de bindingcontrole komen.
- Blokkering via bestaand apparaatbeheer maakt de binding ongeldig en verwijdert de sessies. Allow-listverwijdering en bindingrotatie trekken ook sessies in. Sanctum controleert binding, user, organisatie en actief lidmaatschap bij authenticatie, ook op routes zonder apparaatregistratiemiddleware.
- Eén actieve apparaatsessie per device-account; opnieuw inloggen vervangt de vorige sessie. Een ander installatie-ID kan een bestaande sessie niet gebruiken op de gecontroleerde app-API-routes.
- Share- en cloudrechten volgen de normale user-ID. Getest: eigen cloudkaarten zichtbaar, andere device-account ziet ze niet, eigen Share-link maken, geldige link ontvangen, ongeldige link weigeren en geen beheer/schrijven/verwijderen namens een ander device-account.

## Nog vereist voor gebruik met echte iPads

1. Voer na vrijgave van een deployment de nieuwe migratie `2026_10_16_100000_add_connect_device_bindings.php` uit. Deze is nu alleen in de geïsoleerde testdatabase uitgevoerd; de bestaande lokale en productiedatabases zijn niet gemigreerd.
2. Registreer het **volledige echte UDID uit de betrouwbare MDM-inventaris** in de juiste Connect-organisatie via de bestaande allow-list of `POST /api/v1/connect/mdm/devices` met het organisatiespecifieke MDM-Bearertoken. IDFV/Capacitor Device.getId is niet hetzelfde als het echte UDID.
3. Laat uitsluitend de vertrouwde MDM-server/automatisering `POST /api/v1/connect/mdm/devices/{udid}/binding` uitvoeren met dat MDM-Bearertoken. De respons bevat `device_binding` (64 willekeurige tekens); dit is een geheim, geen UDID.
4. Lever die waarde **alleen aan de bijbehorende beheerde iPad** als managed app configuration:

   ```json
   {
     "device_id": "18D2D99B-VOLLEDIGE-GEREGISTREERDE-UDID",
     "device_binding": "DE-UNIEKE-64-TEKENS-UIT-DE-MDM-API"
   }
   ```

   `device_id` blijft beschikbaar voor bestaande metadata/huisstijl. Authenticatie gebruikt uitsluitend `device_binding`. Zet nooit het organisatie-MDM-token in de app-config. Gebruik HTTPS en neem secrets niet op in URL's, logs, screenshots of gedeelde configuratieprofielen. Gebruik per apparaat een afzonderlijke waarde; de waarde is een bearercredential, geen hardwareattestatie.
5. Gebruik een iOS-build waarin de bestaande `MilMapManagedConfig`-plugin is geregistreerd. Die registratie is in de bron aanwezig, maar installatie en ontvangst van managed config zijn in deze opdracht niet op een echte iPad getest. Andere platformen krijgen via deze nieuwe clientflow geen managed device-login.
6. Na blokkering mag pas na bewuste nieuwe provisioning weer een nieuwe binding worden afgeleverd. Rotatie geeft de nieuwe waarde eenmaal terug en trekt de oude waarde en sessies in.
7. Verwijdering van een allow-listitem bewaart het device-useraccount en diens gegevens. Opnieuw toevoegen/provisionen met hetzelfde prefix kan daardoor een 409 opleveren; herstel/toewijzing vereist dan expliciete beheercontrole. Er is geen automatische accountovername of dataverwijdering toegevoegd.
8. Verifieer op een echte beheerde iPad: app-configbezorging, starten op Home, alle auth-routes, inloggen, herstarten, Share/cloudgebruik, offline foutweergave, blokkeren terwijl ingelogd en weigering van opnieuw inloggen.

De huidige MDM-integratie registreert apparaten; de aanvullende automatisering om het individuele geheim naar iedere iPad te pushen moet nog in jullie MDM worden ingericht. Een apparaat met uitsluitend een geïmporteerd UDID maar zonder afgeleverde binding kan nog niet de veilige Connect-login gebruiken.

## Verificatie

- Backend: **54 tests, 317 assertions geslaagd** voor `ConnectDeviceLoginTest`, `ConnectEnterpriseTest`, `AccountIsolationTest`, `ActivitySharingTest` en `SecurityHardeningTest`.
- Connect-frontend: **10 tests geslaagd**, inclusief daadwerkelijk renderen van de actieve AuthView, routering naar Home, behoud normale login en uitsluiting van de lokale preview in productie.
- Productiebuild: geslaagd met bestaande CSS-volgorde-/bundelwaarschuwingen en verouderde Browserslist-data.
- Browser: lokale Connect-preview zichtbaar zonder e-mailveld; klikken toont alleen een voorbeeldmelding en maakt geen sessie. Normale `/login` toont het bestaande e-mailveld. `/register?connect-preview=1` verwijst zichtbaar naar `/?connect-preview=1`.
- Volledige backendtestset: stopt door de bestaande dubbele methode `AdminController::createConnectOrganization()` (regels 741 en 1053).
- Volledige frontendtestset: fouten in bestaande `account-panels.test.cjs` en `outbox-local-first.test.mjs`; deze bestanden en hun implementaties zijn door deze wijziging niet aangepast.

**Niet gedeployed.** Vrijgave vereist het oplossen van de bredere testblokkades en verificatie met een echte MDM-beheerde iPad. Native iOS-build, MDM-serverconfiguratie en productiegedrag zijn nog niet end-to-end bevestigd.

## Lokale preview

- Connect-Home: http://localhost:8080/?connect-preview=1
- Doorverwijzing: http://localhost:8080/register?connect-preview=1
- Normale login: http://localhost:8080/login

De previewparameter werkt alleen in development, verandert geen backend-authenticatie en maakt geen gebruikerssessie. De frontendontwikkelserver draait op localhost:8080; voor deze visuele preview is geen echte backendlogin nodig.

## Belangrijkste bronbestanden

Backend: `app/Services/ConnectDeviceLogin.php`, `app/Http/Controllers/Auth/ConnectDeviceLoginController.php`, `app/Services/ConnectDeviceRegistry.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/ConnectController.php`, `app/Http/Controllers/Auth/Concerns/IssuesLoginSession.php`, `app/Models/ConnectAllowedDevice.php`, `app/Models/User.php`, `routes/connect.php`, de nieuwe migratie en `tests/Feature/ConnectDeviceLoginTest.php`.

Frontend: `src/services/connectDeviceLogin.js`, `src/services/AuthServices.js`, `src/views/auth/AuthView.vue`, `src/views/Home.vue`, `src/router/index.js` en `tests/connect-device-login.test.mjs`.

Bij de normale login kwam tijdens de tests een bestaande PDO-serialisatiefout in de uitgestelde loginmail aan het licht. De gedeelde loginhandler gebruikt nu een applicatie-terminationcallback voor die mail in plaats van het serialiseren van de controller/request in een queueclosure; normale login is hiermee ook getest.

## Aanvulling Enterprise-rechten

Een actief Connect-lidmaatschap geeft nu alle pakketfuncties, onafhankelijk van een persoonlijk abonnement, proefperiode of beperkende Team-rechtentabel. De server blijft resource-eigendom, samenwerkingsrechten en beheerrollen controleren. De workspace moet wel een actieve Enterprise-licentie houden.

UpgradePrompt gebruikt de actuele serverresponse van het account (`connect_device_account` of `premium.organization.via = enterprise`) en geen lokaal huisstijlcacherecord. Een open upgradevenster sluit zodra de Connect-status beschikbaar komt. Persoonlijke abonnement- en factuurpanelen zijn voor deze accounts verborgen.

Aanvullend getest: geen persoonlijk abonnement of proefperiode, volledige Enterprise-featuretoegang ondanks gesloten Team-rechten, blokkering van toegang bij organisatieblokkade, geen upgradevenster/prijsvraag voor Connect en behoud van het gewone upgradegedrag.
