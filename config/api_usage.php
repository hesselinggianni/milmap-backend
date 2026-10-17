<?php

/*
|--------------------------------------------------------------------------
| API-verbruik van derde partijen
|--------------------------------------------------------------------------
|
| Register van externe diensten die de app (direct vanaf het toestel) of de
| server (via onze proxy's) aanroept. Gebruikt door:
|  - App\Services\ApiUsageMeter         (server-side tellen)
|  - App\Http\Controllers\ApiUsageController (client-ingest + admin-overzicht)
|  - admin.milmap.nl → Analytics → API-verbruik (labels + limieten tonen)
|
| Provider-sleutels zijn snake_case; een onbekende sleutel uit de app valt
| terug op 'other'. `kind` is vrij (snake_case), bv. forecast, tiles, reverse.
|
| scope — over wie de limiet geldt:
|   per_ip    per client-netwerk (toestel roept de dienst direct aan; 400 iPads
|             achter één school-NAT delen dus één budget)
|   per_token per API-sleutel van ons (alle gebruikers samen)
|   global    per server-IP van ons (alleen via de proxy aangeroepen)
|   none      geen bekende limiet
|
| limits — aantallen per second/minute/hour/day/month (null = onbekend).
| monthly_free — gratis maandtegoed per kind (Mapbox e.d.), voor waarschuwingen.
| hosts — ter documentatie / synchroon houden met de classifier in
|         MilMap-Frontend/src/services/apiUsageMeter.js.
|
*/

return [

    // Rijen ouder dan dit worden dagelijks opgeruimd (model:prune).
    'retention_days' => 180,

    // Vanaf welk deel van een limiet het admin-overzicht waarschuwt.
    'warn_ratio' => 0.8,

    // Throttle van POST /api/v1/api-usage (per minuut). Ruim per IP: een
    // klas van 400+ iPads achter één NAT flusht elk ~5 minuten.
    'ingest_limits' => [
        'user' => 30,
        'ip'   => 3000,
    ],

    'providers' => [

        'open_meteo' => [
            'label'  => 'Open-Meteo',
            'scope'  => 'per_ip',
            'limits' => ['minute' => 600, 'hour' => 5000, 'day' => 10000, 'month' => 300000],
            'note'   => 'Gratis tier (alleen niet-commercieel); limieten gelden per IP-adres. Scholen met één NAT-IP delen één budget.',
            'hosts'  => ['api.open-meteo.com', 'archive-api.open-meteo.com', 'air-quality-api.open-meteo.com', 'marine-api.open-meteo.com', 'geocoding-api.open-meteo.com'],
        ],

        'mapbox' => [
            'label'        => 'Mapbox',
            'scope'        => 'per_token',
            'limits'       => [],
            'monthly_free' => ['tiles' => 750000, 'vector_tiles' => 200000, 'static_image' => 50000, 'geocoding' => 100000],
            'note'         => 'Gefactureerd per access-token (alle gebruikers samen). Gratis per maand: 750k raster-tiles, 200k vector-tiles, 50k static images, 100k geocoding.',
            'hosts'        => ['api.mapbox.com'],
        ],

        'nominatim' => [
            'label'  => 'Nominatim (OSM)',
            'scope'  => 'global',
            'limits' => ['second' => 1],
            'note'   => 'Usage policy: maximaal 1 request/seconde vanaf onze server; alleen via de cachende proxy.',
            'hosts'  => ['nominatim.openstreetmap.org'],
        ],

        'overpass' => [
            'label'  => 'Overpass API',
            'scope'  => 'per_ip',
            'limits' => ['day' => 10000],
            'note'   => 'Fair use van overpass-api.de: ca. 10.000 queries/dag per IP.',
            'hosts'  => ['overpass-api.de'],
        ],

        'osrm' => [
            'label'  => 'OSRM (demo)',
            'scope'  => 'per_ip',
            'limits' => ['second' => 1],
            'note'   => 'Publieke demoserver: ca. 1 request/seconde, niet-commercieel gebruik.',
            'hosts'  => ['router.project-osrm.org'],
        ],

        'valhalla' => [
            'label'  => 'Valhalla (FOSSGIS)',
            'scope'  => 'per_ip',
            'limits' => [],
            'note'   => 'Publieke demoserver van FOSSGIS: fair use, geen vaste limiet gepubliceerd.',
            'hosts'  => ['valhalla1.openstreetmap.de'],
        ],

        'pdok' => [
            'label'  => 'PDOK',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Open overheidsdata (NL); geen bekende limiet.',
            'hosts'  => ['service.pdok.nl', 'api.pdok.nl'],
        ],

        'ip_api' => [
            'label'  => 'ip-api.com',
            'scope'  => 'global',
            'limits' => ['minute' => 45],
            'note'   => 'Gratis endpoint: 45 requests/minuut per server-IP (login-/beveiligingsmails).',
            'hosts'  => ['ip-api.com'],
        ],

        'country_is' => [
            'label'  => 'country.is',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'GeoIP-terugval wanneer de lokale DB-IP-database ontbreekt.',
            'hosts'  => ['api.country.is'],
        ],

        'open_elevation' => [
            'label'  => 'Open-Elevation',
            'scope'  => 'global',
            'limits' => [],
            'note'   => 'Publieke instance (routegenerator); fair use.',
            'hosts'  => ['api.open-elevation.com'],
        ],

        'emodnet' => [
            'label'  => 'EMODnet Bathymetry',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'WMS voor de waterdiepte-laag (Europa), via de tegelproxy.',
            'hosts'  => ['ows.emodnet-bathymetry.eu'],
        ],

        'gebco' => [
            'label'  => 'GEBCO',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'WMS voor de waterdiepte-laag buiten Europa, via de tegelproxy.',
            'hosts'  => ['www.gebco.net'],
        ],

        'kartverket' => [
            'label'  => 'Kartverket',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Noorse topokaart (WMTS).',
            'hosts'  => ['cache.kartverket.no'],
        ],

        'bkg' => [
            'label'  => 'BKG (TopPlus)',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Duitse topokaart (geodatenzentrum.de).',
            'hosts'  => ['sgx.geodatenzentrum.de'],
        ],

        'usgs' => [
            'label'  => 'USGS National Map',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Amerikaanse topokaart.',
            'hosts'  => ['basemap.nationalmap.gov'],
        ],

        'geoportal_pl' => [
            'label'  => 'Geoportal (PL)',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Poolse topokaart (WMTS).',
            'hosts'  => ['mapy.geoportal.gov.pl'],
        ],

        'geoportal_lt' => [
            'label'  => 'Geoportal (LT)',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Litouwse topokaart.',
            'hosts'  => ['www.geoportal.lt'],
        ],

        'os_uk' => [
            'label'  => 'Ordnance Survey',
            'scope'  => 'per_token',
            'limits' => [],
            'note'   => 'OS Data Hub: per API-sleutel; premium-stijlen tellen tegen het gratis maandtegoed.',
            'hosts'  => ['api.os.uk'],
        ],

        'rainviewer' => [
            'label'  => 'RainViewer',
            'scope'  => 'per_ip',
            'limits' => [],
            'note'   => 'Radar-tegels (weerlaag); gratis API met fair-use-beperkingen.',
            'hosts'  => ['api.rainviewer.com', 'tilecache.rainviewer.com'],
        ],

        'nasa_gibs' => [
            'label'  => 'NASA GIBS',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Satelliet-/wolkentegels (weerlaag).',
            'hosts'  => ['gibs.earthdata.nasa.gov'],
        ],

        'google_maps' => [
            'label'  => 'Google Maps (links)',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Verkorte Google Maps-links uitklappen (kaartlinks plakken).',
            'hosts'  => ['maps.app.goo.gl', 'goo.gl'],
        ],

        'apple_maps' => [
            'label'  => 'Apple Maps (links)',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Verkorte Apple Maps-links uitklappen (kaartlinks plakken).',
            'hosts'  => ['maps.apple.com'],
        ],

        'osm_tiles' => [
            'label'  => 'OpenStreetMap-tegels',
            'scope'  => 'per_ip',
            'limits' => [],
            'note'   => 'tile.openstreetmap.org — alleen terugval-basiskaart; tile usage policy verbiedt zwaar gebruik.',
            'hosts'  => ['tile.openstreetmap.org'],
        ],

        'other' => [
            'label'  => 'Overig',
            'scope'  => 'none',
            'limits' => [],
            'note'   => 'Onbekende provider-sleutel uit de app.',
            'hosts'  => [],
        ],
    ],
];
