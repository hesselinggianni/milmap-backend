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

    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)?milmap\.nl$#i',  // milmap.nl + subdomains (app, api, ws, www)
        '#^https?://localhost(:[0-9]+)?$#i',        // local dev + Android native (https://localhost)
        '#^https?://127\.0\.0\.1(:[0-9]+)?$#i',
        '#^capacitor://localhost$#i',               // Capacitor iOS native WebView origin
        '#^ionic://localhost$#i',                   // legacy Ionic scheme
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
