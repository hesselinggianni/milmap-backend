<?php
use App\Http\Controllers\ConnectController;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/connect')->group(function () {
    // Offerte aanvragen kan ook zonder account (connect.milmap.nl/start).
    Route::post('quote-requests', [ConnectController::class, 'requestQuote'])->middleware('throttle:5,10');
    Route::post('invitations/preview', [ConnectController::class, 'preview'])->middleware('throttle:30,1');
    Route::get('device-branding', [ConnectController::class, 'deviceBranding'])->middleware('throttle:30,1');
    Route::post('device-login/status', [\App\Http\Controllers\Auth\ConnectDeviceLoginController::class, 'status'])->middleware('throttle:30,1');
    Route::post('device-login', [\App\Http\Controllers\Auth\ConnectDeviceLoginController::class, 'login'])->middleware('throttle:10,1');
    // MDM-koppeling: Bearer organisatie-token (geen gebruikerslogin).
    Route::prefix('mdm')->middleware('throttle:60,1')->group(function () {
        Route::post('devices/{udid}/binding', [ConnectController::class, 'mdmBinding']);
        Route::get('devices', [ConnectController::class, 'mdmList']);
        Route::post('devices', [ConnectController::class, 'mdmAdd']);
        Route::delete('devices/{udid}', [ConnectController::class, 'mdmRemove']);
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('invitations/accept', [ConnectController::class, 'accept'])->middleware('throttle:20,1');
        Route::get('organizations', [ConnectController::class, 'index']);
        Route::post('authorize-action', [\App\Http\Controllers\ConnectManagementController::class, 'authorizeAction']);
        Route::match(['GET', 'PUT'], 'organizations/{organization}/policy', [\App\Http\Controllers\ConnectManagementController::class, 'policy']);
        Route::put('organizations/{organization}/members/{member}/permissions', [\App\Http\Controllers\ConnectManagementController::class, 'permissions']);
        Route::put('organizations/{organization}/allowed-devices/{device}/assignment', [\App\Http\Controllers\ConnectManagementController::class, 'assignment']);
        Route::get('organizations/{organization}/audit', [\App\Http\Controllers\ConnectManagementController::class, 'audit']);
        Route::get('organizations/{organization}/resources', [\App\Http\Controllers\ConnectManagementController::class, 'resources']);
        Route::post('organizations/{organization}/resources/transfer', [\App\Http\Controllers\ConnectManagementController::class, 'transfer']);
        Route::get('access', [ConnectController::class, 'access']);
        Route::get('organizations/{organization}/billing/catalog', [\App\Http\Controllers\ConnectBillingController::class, 'catalog']);
        Route::get('organizations/{organization}/billing/orders', [\App\Http\Controllers\ConnectBillingController::class, 'index']);
        Route::post('organizations/{organization}/billing/orders', [\App\Http\Controllers\ConnectBillingController::class, 'store'])->middleware('throttle:10,1');
        Route::post('organizations/{organization}/billing/orders/{order}/cancel', [\App\Http\Controllers\ConnectBillingController::class, 'cancel'])->middleware('throttle:10,1');
        Route::get('organizations/{organization}', [ConnectController::class, 'show']);
        Route::post('organizations/{organization}/license/upgrade', [ConnectController::class, 'upgradeLicense'])->middleware('throttle:10,1');
        Route::post('organizations/{organization}/license/checkout', [ConnectController::class, 'checkoutSeats'])->middleware('throttle:10,1');
        Route::post('organizations/{organization}/license/invoice-request', [ConnectController::class, 'invoiceRequest'])->middleware('throttle:10,1');
        Route::post('organizations/{organization}/members', [ConnectController::class, 'invite'])->middleware('throttle:10,1');
        Route::put('organizations/{organization}/members/{member}', [ConnectController::class, 'update']);
        Route::post('organizations/{organization}/members/{member}/welcome', [ConnectController::class, 'welcome'])->middleware('throttle:5,1');
        Route::delete('organizations/{organization}/members/{member}', [ConnectController::class, 'remove']);
        Route::post('organizations/{organization}/members/{member}/resend', [ConnectController::class, 'resend'])->middleware('throttle:10,1');
        Route::delete('organizations/{organization}/devices/{device}', [ConnectController::class, 'revokeDevice']);
        Route::post('organizations/{organization}/devices/{device}/update', [ConnectController::class, 'requestDeviceUpdate'])->middleware('throttle:20,1');
        Route::put('organizations/{organization}/device-restriction', [ConnectController::class, 'updateDeviceRestriction']);
        Route::post('organizations/{organization}/branding', [ConnectController::class, 'updateBranding'])->middleware('throttle:20,1');
        Route::post('organizations/{organization}/allowed-devices', [ConnectController::class, 'importAllowedDevices'])->middleware('throttle:10,1');
        Route::post('organizations/{organization}/mdm-token', [ConnectController::class, 'createMdmToken'])->middleware('throttle:10,1');
        Route::delete('organizations/{organization}/mdm-token', [ConnectController::class, 'revokeMdmToken']);
        Route::delete('organizations/{organization}/allowed-devices/{allowedDevice}', [ConnectController::class, 'deleteAllowedDevice']);
    });
});
