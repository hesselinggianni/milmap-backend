# Accountisolatie en backendperformance — 19 september 2026

## Hersteld

- Missies mogen serverkaarten alleen koppelen wanneer kaart en missie dezelfde eigenaar hebben. Alleen die eigenaar kan de koppeling veranderen. Lokale kaartverwijzingen leveren nooit serverrechten op.
- De centrale `MapAccess`-service controleert ook bestaande koppelingen bij iedere autorisatie. Een eerder opgeslagen verwijzing naar een kaart van een andere eigenaar verleent dus geen rechten meer. Records worden niet verwijderd.
- Alleen geaccepteerde, bekende rollen geven toegang. Viewer blijft alleen-lezen; editor/admin mag bewerken; verwijderen blijft voor de kaarteigenaar. Meerdere geldige missietoekenningen worden gecombineerd.
- Kaartpolicy, waypoint-/plattegrond-/rapportcontroles, workspace en kaart-/GPS-/plattegrondkanalen gebruiken dezelfde kaartrechten.
- GPS-uitzendingen gebruiken nu daadwerkelijk `PrivateChannel`. De frontend abonneert zich via `echo.private` en ruimt dat abonnement op met `leave`. Een channel-autorisatiecallback beschermde de oude openbare uitzendingen niet.
- De API-limiet heeft een aparte sleutel per account; anonieme requests worden per IP begrensd. Geen tokens of door de client gekozen account-ID's als sleutel.

## Performance

- Activiteitenoverzicht selecteert expliciet de overzichtskolommen. Alleen het startcoördinaat wordt via JSON_EXTRACT uit de track gehaald. De volledige GPS-tracks gaan niet meer van database naar PHP. De database moet de JSON wel lezen om die coördinaat te bepalen.
- Workspacevoorkeuren selecteren toegestane kaart-ID's in één SQL-query, ook voor een batch. De eerdere controle deed extra queries per kaart.
- Batchresolutie van rollen gebruikt maximaal drie extra queries; er is geen gedeelde cache van gebruikersrechten die na intrekken toegang zou blijven verlenen.

## Verificatie

- Gerichte backendtests: 28 tests, 117 assertions geslaagd, inclusief 15 nieuwe accountisolatietests.
- Werkelijke HTTP-routes getest voor lezen/schrijven, ongeldige missiekoppelingen, een geldige koppeling door de eigenaar, viewer/editor, ingetrokken rechten en accountgebonden 429-responsen.
- Batchquery en policy geven dezelfde resultaten voor geldige, vreemde, lokale en verwijderde missiekoppelingen.
- Activiteitenlijst: accountfilter, behouden startmarkering en geen volledig points-veld in de SQL-selectie.
- GPS-eventkanaaltype en channel-autorisatie getest.
- 43 frontendtests geslaagd; gewijzigd MissionLiveMap-component compileert; 15 gewijzigde PHP-bestanden door syntaxcontrole.
- Volledige backend-suite: 35 tests, 126 assertions, 3 bestaande fouten: twee SocialPublisher-tests missen social_accounts; PostServiceTest faalt tijdens een niet-SQLite-compatibele migratie. Alle tests draaiden met een tijdelijke SQLite-geheugendatabase.

## Ingebruikname en grenzen

Backend en frontend samen uitrollen; langlopende queue-/appworkers herstarten zodat oude code geen openbare GPS-events meer uitzendt. Er zijn voor deze wijzigingen geen nieuwe databasemigraties nodig. Dit werk is lokaal uitgevoerd; productie is niet gewijzigd.

Een geldige, expliciet aangemaakte tijdelijke activiteit-deellink blijft leesbaar zonder account. Zonder geldige handtekening vervalt die toegang. Bestaande tests zijn aangepast aan deze reeds aanwezige publieke deelfunctie.

Er is geen organisatie-/tenantdatamodel toegevoegd: isolatie volgt de bestaande eigenaar- en uitnodigingsrechten. Een volledige audit van alle endpoints, opslagrechten en productie-infrastructuur valt buiten deze gerichte reparatie. Tests gebruiken SQLite; JSON_EXTRACT wordt ook door de gebruikte MySQL-queryvorm ondersteund, maar deze wijzigingen zijn niet tegen een productie-MySQL-database of een live Reverb-server getest.

Privé-WebSocketkanalen controleren toegang bij het abonneren. Onmiddellijke serverzijdige beëindiging van reeds geopende verbindingen na intrekken van rechten is hiermee niet geïmplementeerd; daarvoor is aanvullende verbinding-/revocatieafhandeling nodig.
