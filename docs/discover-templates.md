# Discover route templates

A bundled starter catalogue from the official OpenStreetMap API, downloaded on
2026-10-30. Raw API snapshots remain available in `database/data/discover` so the
source data can be inspected and redistributed under ODbL 1.0.

Source: https://www.openstreetmap.org/copyright
Licence: https://opendatacommons.org/licenses/odbl/1-0/
Attribution: © OpenStreetMap contributors.

## Import

From the backend directory:

```sh
php artisan routes:import-templates --dry-run
php artisan routes:import-templates
```

The normal route publishing and discovery migrations must already be installed.
The importer creates a dedicated catalogue identity (`discover-catalog@milmap.invalid`)
with a random password and the public profile `milmap-routes`. It never enables an
existing private user profile. Repeating the import updates the same source records;
it does not replace users' copies. A disabled catalogue profile stops the import.

Routes appear through the existing Discover API, geographic search and sport filters.
Opening, GPX export and copying use the existing public route flow and its account /
premium requirements. This is a free template catalogue, without checkout or payments.

## Included

- 1127213: Appelpad
- 1216918: Zwarte Wandelroute (Veluwezoom)
- 1516725: Ankeveense Plassen
- 2828574: Langs wad, plas, duin, strand en vuurtoren (Schiermonnikoog)
- 5351805: Avervoorderpad
- 6559865: 's-Gravelandse Buitenplaatsen

Two additional snapshots are intentionally not published: Fort Spijkerboor (1100026,
disconnected ways) and Aderwinkelpad (3846102, branch roles). No artificial straight
connectors are inserted. Nested routes and unsupported roles require manual review.

Distances come from the complete source path; travel times are estimates at 5 km/h.
Elevation and difficulty are not invented. Source attribution and relation URL are
saved with every route and retained when copied. OSM geometry is a recorded route,
not a current inspection of access or closures. Descriptions and photos from other
platforms are not included.

## Refresh

Retrieve the source snapshot from
`https://www.openstreetmap.org/api/0.6/relation/RELATION_ID/full.json`, replace the
matching JSON file, validate and run the importer again. Review source changes and
skipped routes before publishing. Withdrawing a template uses the existing route
visibility control; copies already saved by users remain independent.

## Verification

```sh
php artisan test --compact tests/Unit/DiscoverTemplateTest.php tests/Feature/RouteDiscoverTest.php
```

## Live installation (2026-10-30)

Six templates were imported into the live API after installing only the public
profile/discovery controllers, presenter, geometry helper, model additions, and
the two relevant migrations. The pre-update API routes and RouteMap model are
backed up on the server in `storage/app/discover-backup`.

Local development now supports `VUE_APP_API_URL=/live-api` in `.env.local`.
The Vue development server proxies that path to `https://api.milmap.nl`, avoiding
changes to production CORS policy. Restart the dev server after changing this setting.
The public catalogue can be checked at `/api/v1/profiles/milmap-routes` (six routes).
Discover remains geographic: zoom to the Netherlands to see the mainland templates;
the Schiermonnikoog template appears when searching around that island.
