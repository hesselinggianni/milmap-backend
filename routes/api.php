<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;


use App\Http\Controllers\UserController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\SearchHistoryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\UserLocationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RouteMapController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\ContactTicketController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\MapShareController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\MapCollaboratorController;
use App\Http\Controllers\RouteGenerationController;

/* Auth group */

Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::post('/register', [RegisterController::class, 'store']);
    Route::post('/login', [LoginController::class, 'store']);
    Route::post('/logout', [LogoutController::class, 'destroy'])->middleware('auth:sanctum');
    Route::post('/logout-all', [LogoutController::class, 'logoutFromAllDevices'])->middleware('auth:sanctum');

    Route::post('/password/reset-link', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/password/reset', [PasswordResetController::class, 'resetPassword']);

    // Admin authentication (public endpoints)
    Route::post('/admin/request-code', [AdminAuthController::class, 'requestCode']);
    Route::post('/admin/verify-code', [AdminAuthController::class, 'verifyCode']);

    // Public share endpoint (no auth required)
    Route::get('/share/{token}', [MapShareController::class, 'showByToken']);

    // Public feature requests (no auth required)
    Route::post('/feature-requests', [FeatureRequestController::class, 'store']);

    // Public contact tickets (no auth required)
    Route::post('/contact-tickets', [ContactTicketController::class, 'store']);

    // Map collaboration invitations (public - no auth required for acceptance)
    Route::post('/invitations/{token}/accept', [MapCollaboratorController::class, 'acceptInvitation']);

    // Stripe webhook — public, verified via signature
    Route::post('/billing/webhook', [BillingController::class, 'handleWebhook']);

    // Guest checkout — public, maakt account aan als nodig
    Route::post('/billing/guest-checkout', [BillingController::class, 'guestCheckout']);

    // Public share access endpoints (no auth required, but must have valid share token)
    Route::get('/maps/{mapId}/locations', [LocationController::class, 'index']);
    Route::get('/maps/{mapId}/routemaps', [RouteMapController::class, 'getByMapId']);
});


Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
     
        /* User group */

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/me', [UserController::class, 'me']);

        // Self-service endpoints (geen id nodig — gebruikt Auth::user())
        Route::put('/user/profile', [UserController::class, 'updateProfile']);
        Route::put('/user/password', [UserController::class, 'changePassword']);

        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
    


        Route::get('/maps', [MapController::class, 'index']);
        Route::get('/maps/me', [MapController::class, 'myMaps']);
        Route::get('/maps/{id}/{lonlat}', [MapController::class, 'show']);
        Route::post('/maps', [MapController::class, 'store']);
        Route::put('/maps/{id}', [MapController::class, 'update']);
        Route::delete('/maps/{id}', [MapController::class, 'destroy']);
        
        
        Route::get('/search-history', [SearchHistoryController::class, 'index']);
        Route::post('/search-history', [SearchHistoryController::class, 'store']);
        Route::delete('/search-history/{id}', [SearchHistoryController::class, 'destroy']);
        Route::delete('/search-history', [SearchHistoryController::class, 'clear']);

      
        Route::get('/routemaps', [RouteMapController::class, 'index']);
        Route::post('/routemaps', [RouteMapController::class, 'store']);
        Route::get('/routemaps/{id}', [RouteMapController::class, 'show']);
        Route::put('/routemaps/{id}', [RouteMapController::class, 'update']);
        Route::delete('/routemaps/{id}', [RouteMapController::class, 'destroy']);
        Route::delete('/routemaps', [RouteMapController::class, 'clear']);
        Route::get('/maps/{mapId}routemaps', [RouteMapController::class, 'index']);

        // Route generation routes
        Route::post('/routemaps/{routeMapId}/generate-route', [RouteGenerationController::class, 'generateRoute']);
        Route::get('/routemaps/{routeMapId}/generated-routes', [RouteGenerationController::class, 'listGeneratedRoutes']);
        Route::get('/generated-routes/{id}', [RouteGenerationController::class, 'getGeneratedRoute']);
        Route::post('/generated-routes/{id}/apply', [RouteGenerationController::class, 'applyRoute']);
        Route::delete('/generated-routes/{id}', [RouteGenerationController::class, 'deleteGeneratedRoute']);

        // Checkpoint image upload routes
        Route::post('/routemaps/{id}/checkpoints/{checkpointId}/upload-image', [RouteMapController::class, 'uploadCheckpointImage']);
        Route::delete('/routemaps/{id}/checkpoints/{checkpointId}/image', [RouteMapController::class, 'deleteCheckpointImage']);

        // Location markers routes (POST/PUT/DELETE require auth)
        Route::post('/maps/{mapId}/locations', [LocationController::class, 'store']);
        Route::get('/maps/{mapId}/locations/{locationId}', [LocationController::class, 'show']);
        Route::put('/maps/{mapId}/locations/{locationId}', [LocationController::class, 'update']);
        Route::delete('/maps/{mapId}/locations/{locationId}', [LocationController::class, 'destroy']);
        Route::delete('/maps/{mapId}/locations', [LocationController::class, 'clear']);

        // Live user location sharing routes
        Route::post('/maps/{mapId}/user-locations/update', [UserLocationController::class, 'update']);
        Route::get('/maps/{mapId}/user-locations', [UserLocationController::class, 'getLocations']);
        Route::delete('/maps/{mapId}/user-locations/stop-sharing', [UserLocationController::class, 'stopSharing']);

        // Reports routes
        Route::get('/reports', [ReportController::class, 'index']);
        Route::post('/reports', [ReportController::class, 'store']);
        Route::get('/reports/{id}', [ReportController::class, 'show']);
        Route::put('/reports/{id}', [ReportController::class, 'update']);
        Route::delete('/reports/{id}', [ReportController::class, 'destroy']);
        Route::get('/reports/category/{category}', [ReportController::class, 'getByCategory']);
        Route::get('/reports/maps/{mapId}', [ReportController::class, 'getByMap']);

        // Map sharing routes
        Route::get('/maps/{mapId}/shares', [MapShareController::class, 'index']);
        Route::post('/maps/{mapId}/shares', [MapShareController::class, 'store']);
        Route::delete('/maps/{mapId}/shares/{shareId}', [MapShareController::class, 'destroy']);

        // Map collaborators routes
        Route::get('/maps/{mapId}/collaborators', [MapCollaboratorController::class, 'index']);
        Route::post('/maps/{mapId}/collaborators', [MapCollaboratorController::class, 'store']);
        Route::delete('/maps/{mapId}/collaborators/{userId}', [MapCollaboratorController::class, 'destroy']);
        Route::get('/users/search', [MapCollaboratorController::class, 'searchUsers']);

        // Bug report route
        Route::post('/bug-reports', [BugReportController::class, 'store']);

        // Billing (Stripe)
        Route::prefix('billing')->group(function () {
            Route::get('/subscription', [BillingController::class, 'subscription']);
            Route::post('/checkout', [BillingController::class, 'createCheckout']);
            Route::post('/portal', [BillingController::class, 'createPortal']);
            Route::get('/session', [BillingController::class, 'verifySession']);
        });

        // Admin routes (protected by AdminAuth middleware)
        Route::middleware('admin.auth')->group(function () {
            Route::get('/admin/stats', [AdminController::class, 'getDashboardStats']);
            Route::delete('/admin/users/{userId}', [AdminController::class, 'deleteUser']);
            Route::patch('/admin/users/{userId}/admin-status', [AdminController::class, 'toggleAdminStatus']);
            Route::post('/admin/users/{userId}/reset-password', [AdminController::class, 'resetUserPassword']);
        });

    });
});