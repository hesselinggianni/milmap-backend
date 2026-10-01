<?php

return [
    'paths' => ['api/*', 'api', '/api', 'sanctum/csrf-cookie', '/login', '/logout'],

    'allowed_methods' => ['*'],

    // SECURITY: never pair a wildcard origin with credentials. Auth is a Sanctum
    // Bearer token, but withCredentials is on (CSRF cookie), so we must reflect a
    // specific origin. We allow only MilMap's own web/API/WS domains, localhost
    // (dev + Android `https://localhost`), and the Capacitor native scheme.
    // Arbitrary third-party sites (e.g. https://evil.com) are rejected.
    'allowed_origins' => [],

    'allowed_origins_patterns' => array_merge([
        '#^https://([a-z0-9-]+\.)?milmap\.nl$#i',  // milmap.nl + subdomains (app, api, ws, www)
        '#^https://localhost$#i',                   // Android native WebView (androidScheme: https, geen poort)
        '#^capacitor://localhost$#i',               // Capacitor iOS native WebView origin
        '#^ionic://localhost$#i',                   // legacy Ionic scheme
    ], env('APP_ENV') === 'production' ? [] : [
        // Alleen buiten productie: lokale dev-servers op willekeurige poort.
        // In productie zou dit elke lokaal draaiende webpagina (met cookies)
        // toegang geven tot de API.
        '#^https?://localhost(:[0-9]+)?$#i',
        '#^https?://127\.0\.0\.1(:[0-9]+)?$#i',
    ]),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,
];
