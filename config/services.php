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

    // Gedeeld geheim waarmee de headless deploy-app de /deploy/* endpoints
    // benadert (header: X-Deploy-Token). Niet gezet → endpoints weigeren alles.
    'deploy' => [
        'todo_token' => env('DEPLOY_TODO_TOKEN'),
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

];
