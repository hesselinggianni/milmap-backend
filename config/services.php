<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // Mapbox — server-side token voor de terrain-tile-proxy (TerrainTileController).
    // Waarde komt uit .env (MAPBOX_TOKEN). Geen hardcoded fallback: GitHub's
    // push-protection blokkeert Mapbox-tokens in de code (ook de publieke
    // pk-variant), dus de token hoort in .env — lokaal én op de server.
    'mapbox' => [
        'token' => env('MAPBOX_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // First-party analytics (app_events + site_events): IP-adressen die nooit
    // meetellen, naast loopback (bv. het IP van waaruit het team zelf de
    // productie-app test). Kommagescheiden lijst, leeg = niemand uitgesloten.
    'analytics' => [
        'excluded_ips' => env('ANALYTICS_EXCLUDED_IPS', ''),
    ],

    // Gedeeld geheim waarmee de headless deploy-app de /deploy/* endpoints
    // benadert (header: X-Deploy-Token). Niet gezet → endpoints weigeren alles.
    'deploy' => [
        'todo_token' => env('DEPLOY_TODO_TOKEN'),
    ],

    // Gedeeld geheim waarmee ota-release.sh (MilMap-Frontend) nieuwe OTA-bundels
    // publiceert (header: X-Ota-Token). Niet gezet → publish-endpoint weigert alles.
    'ota' => [
        'publish_token' => env('OTA_PUBLISH_TOKEN'),
    ],

    // Anthropic (Claude) — gebruikt door de SEO-CMS "Genereer met Claude"-knop.
    // Zonder ANTHROPIC_API_KEY faalt de generate-endpoint netjes (422); de rest
    // van het CMS werkt gewoon. SDK-alternatief: composer require anthropic-ai/sdk.
    'anthropic' => [
        'key'   => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
    ],

    // Google PageSpeed Insights — optionele API-sleutel voor hogere quota.
    // Zonder sleutel werkt de meting bij laag volume ook; de sleutel kan óók
    // in-app via Settings (pagespeed_api_key) worden gezet, die wint hiervan.
    'pagespeed' => [
        'key' => env('PAGESPEED_API_KEY'),
    ],


    // Inloggen met Apple. bundle_id = de App ID van de iOS-app (audience van
    // tokens uit de app), service_id = de Services ID voor het web. Sleutel en
    // team zijn nodig zodra we ook server-to-server met Apple praten
    // (token-intrekking bij accountverwijdering).
    'apple' => [
        'bundle_id'  => env('APPLE_BUNDLE_ID', 'nl.milmap.app'),
        'service_id' => env('APPLE_SERVICE_ID'),
        'team_id'    => env('APPLE_TEAM_ID'),
        'key_id'     => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],

    // Inloggen met Google. Elk platform (web/iOS/Android) heeft in Google
    // Cloud Console een EIGEN OAuth-client-id — een ID-token draagt als
    // audience precies het client-id waarmee het is aangevraagd. We
    // accepteren een token dus als de audience een van deze drie is.
    'google' => [
        'web_client_id'     => env('GOOGLE_WEB_CLIENT_ID'),
        'ios_client_id'     => env('GOOGLE_IOS_CLIENT_ID'),
        'android_client_id' => env('GOOGLE_ANDROID_CLIENT_ID'),
    ],

    // Garmin Connect Developer Program — OAuth 2.0 + PKCE. client_id/secret
    // kunnen ook via het admin-scherm (Setting garmin_client_id/secret)
    // worden gezet, die winnen dan van deze env-waarden. Zie
    // App\Services\GarminService voor de endpoint-URL's (nog te VERIFYen
    // zodra er portaaltoegang tot Garmin's developer-docs is).
    'garmin' => [
        'client_id'      => env('GARMIN_CLIENT_ID'),
        'client_secret'  => env('GARMIN_CLIENT_SECRET'),
        'redirect_uri'   => env('GARMIN_REDIRECT_URI', env('APP_URL') . '/api/v1/auth/garmin/callback'),
        'webhook_secret' => env('GARMIN_WEBHOOK_SECRET'),
    ],
];
