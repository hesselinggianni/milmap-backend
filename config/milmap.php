<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App download links
    |--------------------------------------------------------------------------
    | Gebruikt door de app-download-mail (AppDownloadMail). Zet de echte store-
    | URL's in .env zodra de app live staat; tot die tijd wijzen ze naar de
    | landingspagina op milmap.nl/app.
    */
    'app_store_url'  => env('APP_STORE_URL', 'https://milmap.nl/app'),
    'play_store_url' => env('PLAY_STORE_URL', 'https://milmap.nl/app'),
    'web_app_url'    => rtrim(env('APP_WEB_URL', 'https://app.milmap.nl'), '/'),

];
