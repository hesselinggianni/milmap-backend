<?php

use Illuminate\Support\Facades\Facade;

return [
    'name' => env('APP_NAME', 'Crypto Trading Bot'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [],
    'maintenance' => [
        'driver' => 'file',
    ],
    'providers' => [
        App\Providers\AppServiceProvider::class,
        App\Providers\BotServiceProvider::class,
    ],
    'aliases' => Facade::defaultAliases()->toArray(),
];
