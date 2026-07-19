<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'crypto-trading-bot',
    'status' => 'ok',
    'docs' => 'See README.md — this is an API-only backend.',
]));
