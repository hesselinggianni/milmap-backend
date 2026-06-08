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
use App\Http\Controllers\AdminMailAccountController;
use App\Http\Controllers\AdminMailboxController;
use App\Http\Controllers\AdminBillingController;
use App\Http\Controllers\MapShareController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\MapCollaboratorController;
use App\Http\Controllers\RouteGenerationController;
use App\Http\Controllers\ChatKeyController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\MissionCollaboratorController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\ChatAttachmentController;
use App\Http\Controllers\ChatInviteController;
use App\Http\Controllers\ChatRequestController;
use App\Http\Controllers\MissionTrackController;
use App\Http\Controllers\MissionEvaluationController;
use App\Http\Controllers\MissionCommsController;

/* Auth group */

Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::post('/register', [RegisterController::class, 'store']);
    // ── Client-side error reporting (no auth required) ───────────
    Route::post('/client-errors', [ClientErrorController::class, 'store'])
        ->middleware('throttle:30,1');
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

    // Invitation preview — the /invite/{token} page reads this before login/registration.
    Route::get('/invitations/token/{token}', [InvitationController::class, 'preview']);

    // Stripe webhook — public, verified via signature
    Route::post('/billing/webhook', [BillingController::class, 'handleWebhook']);

    // Guest checkout — public, maakt account aan als nodig
    Route::post('/billing/guest-checkout', [BillingController::class, 'guestCheckout']);

    // Check of er al een account bestaat met dit e-mailadres (vóór guest-checkout).
    // Rate-limited om enumeratie af te remmen.
    Route::post('/billing/check-email', [BillingController::class, 'checkEmail'])
        ->middleware('throttle:10,1');

    // Public share access endpoints (no auth required, but must have valid share token)
    Route::get('/maps/{mapId}/locations', [LocationController::class, 'index']);
    Route::get('/maps/{mapId}/routemaps', [RouteMapController::class, 'getByMapId']);
});


Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::middleware(['auth:sanctum', \App\Http\Middleware\TrackLastSeen::class])->group(function () {

        /* User group */

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/me', [UserController::class, 'me']);

        // Self-service endpoints (geen id nodig — gebruikt Auth::user())
        Route::put('/user/profile', [UserController::class, 'updateProfile']);
        Route::put('/user/settings', [UserController::class, 'updateSettings']);
        Route::put('/user/password', [UserController::class, 'changePassword']);

        // Literal route MUST come before /users/{id} or "search" is captured as an id.
        Route::get('/users/search', [MapCollaboratorController::class, 'searchUsers']);

        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);

        // ── Invitations & activity (Hub inbox / timeline) ──────────────
        // Literal routes first so they aren't captured by the {id} param.
        Route::get('/invitations/incoming', [InvitationController::class, 'incoming']);
        Route::get('/invitations/outgoing', [InvitationController::class, 'outgoing']);
        Route::post('/invitations/token/{token}/accept', [InvitationController::class, 'accept']);
        Route::post('/invitations/token/{token}/decline', [InvitationController::class, 'decline']);
        Route::delete('/invitations/{id}', [InvitationController::class, 'revoke']);
        Route::get('/activity/timeline', [InvitationController::class, 'timeline']);

        // ── Teams (curated rosters → invite a whole group at once) ─────
        Route::get('/teams', [TeamController::class, 'index']);
        Route::post('/teams', [TeamController::class, 'store']);
        Route::post('/teams/{team}/members', [TeamController::class, 'addMember']);
        Route::delete('/teams/{team}/members/{member}', [TeamController::class, 'removeMember']);
        Route::post('/teams/{team}/invite', [TeamController::class, 'invite']);
        Route::get('/teams/{team}', [TeamController::class, 'show']);
        Route::put('/teams/{team}', [TeamController::class, 'update']);
        Route::delete('/teams/{team}', [TeamController::class, 'destroy']);



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
        Route::put('/maps/{mapId}/collaborators/{userId}', [MapCollaboratorController::class, 'updateRole']);
        Route::delete('/maps/{mapId}/collaborators/{userId}', [MapCollaboratorController::class, 'destroy']);

        // ── Missions (owner + collaborators with roles) ──────────────────
        // Literal invitation routes first so they aren't captured by {id}.
        Route::get('/missions/invitations/pending', [MissionCollaboratorController::class, 'myInvitations']);
        Route::post('/missions/invitations/{id}/accept', [MissionCollaboratorController::class, 'accept']);
        Route::delete('/missions/invitations/{id}', [MissionCollaboratorController::class, 'decline']);

        Route::get('/missions', [MissionController::class, 'index']);
        Route::post('/missions', [MissionController::class, 'store']);
        Route::get('/missions/{id}', [MissionController::class, 'show']);
        Route::put('/missions/{id}', [MissionController::class, 'update']);
        Route::delete('/missions/{id}', [MissionController::class, 'destroy']);

        // Mission collaborators (invite with roles, manage rights)
        Route::get('/missions/{missionId}/collaborators', [MissionCollaboratorController::class, 'index']);
        Route::post('/missions/{missionId}/collaborators', [MissionCollaboratorController::class, 'store']);
        Route::put('/missions/{missionId}/collaborators/{userId}', [MissionCollaboratorController::class, 'updateRole']);
        Route::delete('/missions/{missionId}/collaborators/{userId}', [MissionCollaboratorController::class, 'destroy']);

        // Mission GPS tracking (navigation feature)
        Route::post('/missions/{id}/tracks', [MissionTrackController::class, 'store']);
        Route::get('/missions/{id}/tracks/live', [MissionTrackController::class, 'live']);
        Route::get('/missions/{id}/tracks/{userId}', [MissionTrackController::class, 'userTrack']);

        // Mission hot-debrief evaluations
        Route::get('/missions/{id}/evaluations/mine', [MissionEvaluationController::class, 'mine']);
        Route::get('/missions/{id}/evaluations', [MissionEvaluationController::class, 'index']);
        Route::post('/missions/{id}/evaluations', [MissionEvaluationController::class, 'store']);

        // Mission comms (group chat + participant roster for the Comms tab)
        Route::get('/missions/{id}/comms/participants', [MissionCommsController::class, 'participants']);
        Route::get('/missions/{id}/comms/conversation', [MissionCommsController::class, 'conversation']);

        // ── Chat (E2EE 1-on-1) ──────────────────────────────────
        // Public-key exchange for sealed-box encryption
        Route::put('/chat/keys/me', [ChatKeyController::class, 'store']);
        Route::get('/chat/keys/me', [ChatKeyController::class, 'me']);
        // Zero-knowledge key escrow (pincode-wrapped private key for cross-device).
        // MUST precede the /chat/keys/{id} wildcard so "escrow" isn't read as an id.
        Route::put('/chat/keys/escrow', [ChatKeyController::class, 'storeEscrow']);
        Route::get('/chat/keys/escrow', [ChatKeyController::class, 'escrow']);
        Route::get('/chat/keys/{id}', [ChatKeyController::class, 'show']);

        // Conversations
        Route::get('/chat/conversations', [ConversationController::class, 'index']);
        Route::post('/chat/conversations', [ConversationController::class, 'store']);
        Route::get('/chat/conversations/{id}', [ConversationController::class, 'show']);
        Route::post('/chat/conversations/{id}/read', [ConversationController::class, 'markRead']);

        // Messages
        Route::get('/chat/conversations/{id}/messages', [MessageController::class, 'index']);
        Route::post('/chat/conversations/{id}/messages', [MessageController::class, 'store']);

        // Message reactions (WhatsApp-style emoji likes)
        Route::post('/chat/conversations/{id}/messages/{messageId}/reactions', [MessageController::class, 'react']);
        Route::delete('/chat/conversations/{id}/messages/{messageId}/reactions', [MessageController::class, 'unreact']);

        // Chat attachments
        Route::post('/chat/attachments', [ChatAttachmentController::class, 'store']);

        // Chat invite link (for e-mails without an account yet)
        Route::post('/chat/invite', [ChatInviteController::class, 'store']);

        // Chat connection requests (existing accounts must accept first)
        Route::post('/chat/requests', [ChatRequestController::class, 'store']);
        Route::get('/chat/requests/incoming', [ChatRequestController::class, 'incoming']);
        Route::post('/chat/requests/{id}/accept', [ChatRequestController::class, 'accept']);
        Route::post('/chat/requests/{id}/decline', [ChatRequestController::class, 'decline']);

        // Bug report route
        Route::post('/bug-reports', [BugReportController::class, 'store']);

        // Billing (Stripe)
        Route::prefix('billing')->group(function () {
            Route::get('/subscription', [BillingController::class, 'subscription']);
            Route::get('/plans', [BillingController::class, 'availablePlans']);
            Route::post('/checkout', [BillingController::class, 'createCheckout']);
            Route::post('/portal', [BillingController::class, 'createPortal']);
            Route::post('/cancel', [BillingController::class, 'cancel']);
            Route::post('/resume', [BillingController::class, 'resume']);
            Route::post('/change-plan', [BillingController::class, 'changePlan']);
            Route::get('/invoices', [BillingController::class, 'invoices']);
            Route::get('/session', [BillingController::class, 'verifySession']);
        });

        // Admin routes (protected by AdminAuth middleware)
        Route::middleware('admin.auth')->group(function () {
            Route::get('/admin/stats', [AdminController::class, 'getDashboardStats']);
            Route::get('/admin/client-errors', [AdminController::class, 'clientErrors']);
            Route::delete('/admin/client-errors', [AdminController::class, 'clearClientErrors']);

            // ── Stripe billing configuration ────────────────────────────
            Route::get('/admin/billing/prices', [AdminBillingController::class, 'prices']);
            Route::get('/admin/billing/price-map', [AdminBillingController::class, 'priceMap']);
            Route::put('/admin/billing/price-map', [AdminBillingController::class, 'savePriceMap']);
            Route::get('/admin/users/{userId}', [AdminController::class, 'getUser']);
            Route::delete('/admin/users/{userId}', [AdminController::class, 'deleteUser']);
            Route::patch('/admin/users/{userId}/admin-status', [AdminController::class, 'toggleAdminStatus']);
            Route::post('/admin/users/{userId}/reset-password', [AdminController::class, 'resetUserPassword']);

            // ── Admin mail client (IMAP/SMTP inboxes) ───────────────────
            // Inbox configuration (CRUD). Passwords are write-only.
            Route::get('/admin/mail/accounts', [AdminMailAccountController::class, 'index']);
            Route::post('/admin/mail/accounts', [AdminMailAccountController::class, 'store']);
            Route::put('/admin/mail/accounts/{id}', [AdminMailAccountController::class, 'update']);
            Route::delete('/admin/mail/accounts/{id}', [AdminMailAccountController::class, 'destroy']);
            Route::post('/admin/mail/accounts/{id}/test', [AdminMailAccountController::class, 'test']);

            // Live mailbox operations (read / flag / send) per inbox.
            Route::get('/admin/mail/accounts/{id}/folders', [AdminMailboxController::class, 'folders']);
            Route::get('/admin/mail/accounts/{id}/messages', [AdminMailboxController::class, 'messages']);
            Route::get('/admin/mail/accounts/{id}/messages/{uid}', [AdminMailboxController::class, 'show']);
            Route::get('/admin/mail/accounts/{id}/messages/{uid}/attachments/{index}', [AdminMailboxController::class, 'attachment']);
            Route::post('/admin/mail/accounts/{id}/messages/{uid}/flag', [AdminMailboxController::class, 'flag']);
            Route::post('/admin/mail/accounts/{id}/send', [AdminMailboxController::class, 'send']);
        });

    });
});