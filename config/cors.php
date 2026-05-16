<?php 


return [
    'paths' => ['api/*','api','/api', 'sanctum/csrf-cookie', '/login', '/logout'],
    'allowed_methods' => ['*'], // Allow all HTTP methods (GET, POST, etc.)
    'allowed_origins' => ['*'], // Allow requests from localhost:8080
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'], // Allow all headers
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // Enable cookies for cross-origin requests
];


// return [
//     'paths' => ['api/*','api','/api', 'sanctum/csrf-cookie', '/login', '/logout'],
//     'allowed_methods' => ['*'], // Allow all HTTP methods (GET, POST, etc.)
//     // 'allowed_origins' => ['*'], // Allow requests from localhost:8080
//     'allowed_origins' => [
//         'localhost:8080',
//         'localhost:8081',
//         'http://localhost:8080',
//         'http://localhost:8081',
//         'https://app.milmap.nl',
//         'https://api.milmap.nl',
//     ],
//     'allowed_origins_patterns' => [],
//     'allowed_headers' => ['*'], // Allow all headers
//     'exposed_headers' => [],
//     'max_age' => 0,
//     'supports_credentials' => true, // Enable cookies for cross-origin requests
// ];

