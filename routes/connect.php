<?php
use App\Http\Controllers\ConnectController;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/connect')->group(function () {
    Route::post('invitations/preview', [ConnectController::class, 'preview'])->middleware('throttle:30,1');
    Route::get('device-branding', [ConnectController::class, 'deviceBranding'])->middleware('throttle:30,1');
    // MDM-koppeling: Bearer organisatie-token (geen gebruikerslogin).
    Route::prefix('mdm')->middleware('throttle:60,1')->group(function () {
        Route::get('devices', [ConnectController::class, 'mdmList']);
        Route::post('devices', [ConnectController::class, 'mdmAdd']);
        Route::delete('devices/{udid}', [ConnectController::class, 'mdmRemove']);
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('invitations/accept', [ConnectController::class, 'accept'])->middleware('throttle:20,1');
        Route::get('organizations', [ConnectController::class, 'index']);
        Route::get('access', [ConnectController::class, 'access']);
        Route::post('quote-requests', [ConnectController::class, 'requestQuote'])->middleware('throttle:5,10');
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
