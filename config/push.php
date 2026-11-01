<?php

// Push-instellingen. Staan bewust in een config-bestand: `env()` geeft buiten
// config/ de waarde null zodra de config gecachet is (`artisan optimize`, zoals
// op productie), waardoor APNs/FCM dan stil nooit verstuurde.
return [
    'apns' => [
        'key_path'  => env('APNS_KEY_PATH'),
        'key_id'    => env('APNS_KEY_ID'),
        'team_id'   => env('APNS_TEAM_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'nl.milmap.app'),
        'sandbox'   => env('APNS_SANDBOX', false),
    ],
    'fcm' => [
        'service_account_path' => env('FCM_SERVICE_ACCOUNT_PATH'),
    ],
];
