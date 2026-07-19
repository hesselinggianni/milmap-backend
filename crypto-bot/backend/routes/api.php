<?php

use App\Http\Controllers\Api\ControlController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Middleware\ApiTokenAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(ApiTokenAuth::class)->group(function () {
    // Read-only dashboard data.
    Route::get('/status', [DashboardController::class, 'status']);
    Route::get('/portfolio', [DashboardController::class, 'portfolio']);
    Route::get('/positions', [DashboardController::class, 'positions']);
    Route::get('/orders', [DashboardController::class, 'orders']);
    Route::get('/trades', [DashboardController::class, 'trades']);
    Route::get('/events', [DashboardController::class, 'events']);
    Route::get('/strategies', [DashboardController::class, 'strategies']);

    // Limited controls.
    Route::post('/control/halt', [ControlController::class, 'halt']);
    Route::post('/control/resume', [ControlController::class, 'resume']);
    Route::post('/strategies/{id}/activate', [ControlController::class, 'activateStrategy']);
});
