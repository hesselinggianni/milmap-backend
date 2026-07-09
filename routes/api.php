<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\EmailVerificationController;


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
use App\Http\Controllers\AdminAgendaController;
use App\Http\Controllers\AdminMailAccountController;
use App\Http\Controllers\AdminMailboxController;
use App\Http\Controllers\AdminBillingController;
use App\Http\Controllers\MapShareController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\MapCollaboratorController;
use App\Http\Controllers\MapWaypointController;
use App\Http\Controllers\RouteGenerationController;
use App\Http\Controllers\ChatKeyController;
use App\Http\Controllers\ChatPairingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\MissionTaskController;
use App\Http\Controllers\MissionTaskTemplateController;
use App\Http\Controllers\OgroupTemplateController;
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
use App\Http\Controllers\TrashController;
use App\Http\Controllers\MissionBriefingController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\NineLineController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\MeCampaignMailController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\AdminSeoController;
use App\Http\Controllers\MailCampaignController;
use App\Http\Controllers\MailPreferenceController;
use App\Http\Controllers\MailUnsubscribeController;
use App\Http\Controllers\MailTrackingController;
use App\Http\Controllers\ClothingOrderController;
use App\Http\Middleware\RequiresPremium;

/* Auth group */

Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:10,1');
    // ── Client-side error reporting (no auth required) ───────────
    Route::post('/client-errors', [ClientErrorController::class, 'store'])
        ->middleware('throttle:30,1');
    Route::post('/login', [LoginController::class, 'store']);
    Route::post('/logout', [LogoutController::class, 'destroy'])->middleware('auth:sanctum');
    Route::post('/logout-all', [LogoutController::class, 'logoutFromAllDevices'])->middleware('auth:sanctum');

    // ── E-mailverificatie ────────────────────────────────────────────
    // De link uit de mail (publiek; de signed URL is de authenticatie). Resend
    // vereist een ingelogde gebruiker, maar staat bewust BUITEN de verificatie-
    // middleware zodat een gewallde gebruiker 'm nog kan aanroepen.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:30,1')
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware(['auth:sanctum', 'throttle:6,1'])
        ->name('verification.send');

    Route::post('/password/reset-link', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:6,1');
    Route::post('/password/reset', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:10,1');

    // Admin authentication (public endpoints)
    Route::post('/admin/request-code', [AdminAuthController::class, 'requestCode']);
    Route::post('/admin/verify-code', [AdminAuthController::class, 'verifyCode']);

    // Public share endpoint (no auth required)
    Route::get('/share/{token}', [MapShareController::class, 'showByToken']);

    // Status-page domains — public; the Nuxt status page (milmap.nl/status)
    // fetches the enabled domains here. Managed via the admin routes below.
    Route::get('/status/domains', [StatusPageController::class, 'publicIndex'])
        ->middleware('throttle:120,1');

    // Public feature requests (no auth required). Throttled against spam now
    // that submissions are persisted (admin-zicht onder /admin/feature-requests).
    Route::post('/feature-requests', [FeatureRequestController::class, 'store'])
        ->middleware('throttle:20,1');

    // Public contact tickets (no auth required)
    Route::post('/contact-tickets', [ContactTicketController::class, 'store']);

    // Invitation preview — the /invite/{token} page reads this before login/registration.
    Route::get('/invitations/token/{token}', [InvitationController::class, 'preview']);

    // Stripe webhook — public, verified via signature
    Route::post('/billing/webhook', [BillingController::class, 'handleWebhook']);

    // Live Stripe pricing per plan (amount/currency/interval/product_id) — public,
    // used by the checkout & landing pages so the shown price matches Stripe.
    Route::get('/billing/pricing', [BillingController::class, 'pricing']);

    // Guest checkout — public, maakt account aan als nodig
    Route::post('/billing/guest-checkout', [BillingController::class, 'guestCheckout'])
        ->middleware('throttle:10,1');

    // Check of er al een account bestaat met dit e-mailadres (vóór guest-checkout).
    // Rate-limited om enumeratie af te remmen.
    Route::post('/billing/check-email', [BillingController::class, 'checkEmail'])
        ->middleware('throttle:10,1');

    // Public share access endpoints (no auth required, but must have valid share token)
    Route::get('/maps/{mapId}/locations', [LocationController::class, 'index']);
    Route::get('/maps/{mapId}/routemaps', [RouteMapController::class, 'getByMapId']);

    // Marketing leads — publiek formulier vanaf milmap.nl/app om gebruikers
    // te informeren over de release. Throttled tegen e-mail-spam.
    Route::post('/leads', [LeadController::class, 'store'])
        ->middleware('throttle:6,1');

    // SEO-overrides per pagina×taal — publiek uitgelezen door milmap.nl (Nuxt)
    // om de page-meta te overschrijven. Beheer onder /admin/seo.
    Route::get('/seo', [SeoController::class, 'index'])
        ->middleware('throttle:120,1');

    // ── Campagne-mail: publieke afmeld- + tracking-routes ───────────────
    // Bewust hier (api.php) i.p.v. web.php: de SPA-catch-all (/{any}) in web.php
    // zou anders elke GET opslokken. Token komt uit mail_sends.token.
    // ── Tijdelijke kledingbestel-lijst (trainingspak / shirts) ──────────
    // Volledig publiek, los van users — bedoeld om later weer te verwijderen.
    Route::get   ('/clothing/products', [ClothingOrderController::class, 'products']);
    Route::get   ('/clothing/orders',   [ClothingOrderController::class, 'index']);
    Route::post  ('/clothing/orders',   [ClothingOrderController::class, 'store'])->middleware('throttle:30,1');
    // Wijzigen via e-mail-link (geheim edit_token in de URL).
    Route::post  ('/clothing/request-edit',         [ClothingOrderController::class, 'requestEdit'])->middleware('throttle:6,1');
    Route::get   ('/clothing/orders/by-token/{token}', [ClothingOrderController::class, 'showByToken']);
    Route::put   ('/clothing/orders/by-token/{token}', [ClothingOrderController::class, 'updateByToken'])->middleware('throttle:30,1');

    // ── Marcandi bestel-tool (marcandi.milmap.nl) ──────────────────────
    // Publiek: shops bekijken + bestellen. Beheer/overzicht/export achter
    // milmap-admin-auth (in de controller afgedwongen via Auth::guard('sanctum')).
    Route::get ('/marcandi/shops',                 [\App\Http\Controllers\MarcandiController::class, 'shops']);
    Route::get ('/marcandi/shops/{slug}',          [\App\Http\Controllers\MarcandiController::class, 'show']);
    Route::post('/marcandi/shops/{slug}/orders',   [\App\Http\Controllers\MarcandiController::class, 'storeOrder'])->middleware('throttle:30,1');
    // Bijzonderheden (HR) — aparte module op oc.milmap.nl
    Route::post  ('/oc/hr-requests',            [\App\Http\Controllers\OcHrController::class, 'store'])->middleware('throttle:20,1');
    Route::get   ('/oc/admin/hr-requests',      [\App\Http\Controllers\OcHrController::class, 'adminIndex']);
    Route::delete('/oc/admin/hr-requests/{id}', [\App\Http\Controllers\OcHrController::class, 'adminDestroy']);
    // Klant: eigen bestellingen via e-mail-magic-link
    Route::post('/marcandi/request-access',            [\App\Http\Controllers\MarcandiController::class, 'requestAccess'])->middleware('throttle:6,1');
    Route::get ('/marcandi/my/{token}',                [\App\Http\Controllers\MarcandiController::class, 'myOrders']);
    Route::get ('/marcandi/my/{token}/orders/{id}',    [\App\Http\Controllers\MarcandiController::class, 'myOrder']);
    Route::put ('/marcandi/my/{token}/orders/{id}',    [\App\Http\Controllers\MarcandiController::class, 'updateMyOrder'])->middleware('throttle:30,1');
    // Admin (token vereist; controller checkt is_admin):
    Route::get   ('/marcandi/admin/shops',              [\App\Http\Controllers\MarcandiController::class, 'adminShops']);
    Route::post  ('/marcandi/admin/shops',              [\App\Http\Controllers\MarcandiController::class, 'storeShop']);
    Route::put   ('/marcandi/admin/shops/{id}',         [\App\Http\Controllers\MarcandiController::class, 'updateShop']);
    Route::delete('/marcandi/admin/shops/{id}',         [\App\Http\Controllers\MarcandiController::class, 'destroyShop']);
    Route::get   ('/marcandi/admin/shops/{slug}/orders',[\App\Http\Controllers\MarcandiController::class, 'adminOrders']);
    Route::post  ('/marcandi/admin/shops/{id}/products',[\App\Http\Controllers\MarcandiController::class, 'storeProduct']);
    Route::put   ('/marcandi/admin/products/{id}',      [\App\Http\Controllers\MarcandiController::class, 'updateProduct']);
    Route::delete('/marcandi/admin/products/{id}',      [\App\Http\Controllers\MarcandiController::class, 'destroyProduct']);

    Route::get ('/m/u/{token}', [MailUnsubscribeController::class, 'show'])->middleware('throttle:30,1');
    Route::post('/m/u/{token}', [MailUnsubscribeController::class, 'update'])->middleware('throttle:30,1');
    // Open-tracking-pixel (1×1 gif). Ruimere throttle want sommige clients prefetchen.
    Route::get('/m/o/{token}.gif', [MailTrackingController::class, 'pixel'])->middleware('throttle:120,1');
});


Route::prefix('v1')->middleware(['api'])->group(function () {
    Route::middleware(['auth:sanctum', \App\Http\Middleware\TrackLastSeen::class, \App\Http\Middleware\EnsureEmailVerified::class, \App\Http\Middleware\EnsureNotViewOnly::class])->group(function () {

        /* User group */

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/me', [UserController::class, 'me']);

        // Self-service endpoints (geen id nodig — gebruikt Auth::user())
        Route::put('/user/profile', [UserController::class, 'updateProfile']);
        Route::put('/user/settings', [UserController::class, 'updateSettings']);
        Route::put('/user/password', [UserController::class, 'changePassword']);
        Route::post('/user/avatar', [UserController::class, 'uploadAvatar']);
        Route::delete('/user/avatar', [UserController::class, 'deleteAvatar']);

        // E-mailvoorkeuren (Account → Meldingen): per-categorie af-/aanmelden.
        Route::get('/mail-preferences', [MailPreferenceController::class, 'index']);
        Route::put('/mail-preferences', [MailPreferenceController::class, 'update']);

        // Login-historie / actieve sessies (Account → Beveiliging).
        Route::get('/user/sessions', [SessionController::class, 'index']);
        Route::post('/user/sessions/revoke-others', [SessionController::class, 'destroyOthers']);
        Route::delete('/user/sessions/{id}', [SessionController::class, 'destroy']);

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
        // Legacy map-collaborator token accept (superseded by the flow above).
        // Now authenticated + addressee-checked — was previously public.
        Route::post('/invitations/{token}/accept', [MapCollaboratorController::class, 'acceptInvitation']);
        Route::delete('/invitations/{id}', [InvitationController::class, 'revoke']);
        Route::get('/activity/timeline', [InvitationController::class, 'timeline']);

        // ── Teams (curated rosters → invite a whole group at once) ─────
        // Team aanmaken/beheren + gedeelde teamkaarten = premiumfunctie. Lezen
        // (GET) blijft vrij zodat een uitgenodigd lid het team nog ziet; de
        // schrijf-endpoints vragen proef of abonnement (RequiresPremium laat GET
        // door en blokkeert mutaties met 402).
        Route::middleware(RequiresPremium::class . ':teams')->group(function () {
            Route::get('/teams', [TeamController::class, 'index']);
            Route::post('/teams', [TeamController::class, 'store']);
            Route::post('/teams/{team}/members', [TeamController::class, 'addMember']);
            Route::delete('/teams/{team}/members/{member}', [TeamController::class, 'removeMember']);
            Route::post('/teams/{team}/invite', [TeamController::class, 'invite']);
            Route::get('/teams/{team}', [TeamController::class, 'show']);
            Route::put('/teams/{team}', [TeamController::class, 'update']);
            Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
        });



        Route::get('/maps', [MapController::class, 'index']);
        Route::get('/maps/me', [MapController::class, 'myMaps']);
        // Let op: {lonlat} is een legacy coördinaat-segment dat door show() wordt
        // genegeerd. Zonder constraint slokt deze greedy route álle GET
        // sub-resources op (/maps/{id}/shares, /collaborators, /waypoints …) en
        // geeft hij het Map-object terug i.p.v. de sub-resource. De constraint
        // beperkt {lonlat} tot coördinaat-tekens, zodat woord-segmenten
        // doorvallen naar hun eigen route.
        Route::get('/maps/{id}/{lonlat}', [MapController::class, 'show'])
            ->where('lonlat', '[-0-9.,/]+');
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
        Route::patch('/routemaps/{id}/mission', [RouteMapController::class, 'linkMission']);
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

        // Live user location sharing routes — live locatie delen = premiumfunctie.
        Route::post('/maps/{mapId}/user-locations/update', [UserLocationController::class, 'update'])
            ->middleware(RequiresPremium::class . ':live_location');
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

        // Map waypoints (collaboration sync)
        Route::get('/maps/{mapId}/waypoints', [MapWaypointController::class, 'index']);
        Route::post('/maps/{mapId}/waypoints', [MapWaypointController::class, 'store']);
        Route::put('/maps/{mapId}/waypoints/{localId}', [MapWaypointController::class, 'update']);
        Route::delete('/maps/{mapId}/waypoints/{localId}', [MapWaypointController::class, 'destroy']);

        // ── Missions (owner + collaborators with roles) ──────────────────
        // Literal invitation routes first so they aren't captured by {id}.
        Route::get('/missions/invitations/pending', [MissionCollaboratorController::class, 'myInvitations']);
        Route::post('/missions/invitations/{id}/accept', [MissionCollaboratorController::class, 'accept']);
        Route::delete('/missions/invitations/{id}', [MissionCollaboratorController::class, 'decline']);

        Route::get('/missions', [MissionController::class, 'index']);
        // Missie aanmaken = premiumfunctie (proef of abonnement). Bestaande
        // missies bekijken/beheren blijft mogelijk voor uitgenodigde deelnemers.
        Route::post('/missions', [MissionController::class, 'store'])
            ->middleware(RequiresPremium::class . ':missions');
        Route::get('/missions/{id}', [MissionController::class, 'show']);
        Route::put('/missions/{id}', [MissionController::class, 'update']);
        Route::post('/missions/{id}/dispatch-warning-order', [MissionController::class, 'dispatchWarningOrder']);
        Route::delete('/missions/{id}', [MissionController::class, 'destroy']);

        // ── Mission briefing + presentation ──────────────────────────
        Route::get   ('/missions/{id}/presentation',                [MissionBriefingController::class, 'presentation']);
        Route::get   ('/missions/{id}/briefing',                    [MissionBriefingController::class, 'show']);
        Route::put   ('/missions/{id}/briefing',                    [MissionBriefingController::class, 'update']);
        Route::get   ('/missions/{id}/radio-channels',              [MissionBriefingController::class, 'listChannels']);
        Route::post  ('/missions/{id}/radio-channels',              [MissionBriefingController::class, 'storeChannel']);
        Route::put   ('/missions/{id}/radio-channels/{channelId}',  [MissionBriefingController::class, 'updateChannel']);
        Route::delete('/missions/{id}/radio-channels/{channelId}',  [MissionBriefingController::class, 'destroyChannel']);
        Route::get   ('/missions/{id}/risks',                       [MissionBriefingController::class, 'listRisks']);
        Route::post  ('/missions/{id}/risks',                       [MissionBriefingController::class, 'storeRisk']);
        Route::put   ('/missions/{id}/risks/{riskId}',              [MissionBriefingController::class, 'updateRisk']);
        Route::delete('/missions/{id}/risks/{riskId}',              [MissionBriefingController::class, 'destroyRisk']);
        Route::post  ('/missions/{id}/approve',                     [MissionBriefingController::class, 'approve']);
        Route::post  ('/missions/{id}/unlock',                      [MissionBriefingController::class, 'unlock']);

        // ── Mission tasks (kort actiepunten per missie) ──────────────
        // Reorder + apply-template als aparte endpoints zodat één drag-drop
        // een minimale roundtrip blijft (één PUT van een ID-array).
        // Alle taken die aan de ingelogde gebruiker zijn gekoppeld (cross-missie)
        // — voedt de "Mijn taken"-widget op de Hub.
        Route::get   ('/me/tasks',                            [MissionTaskController::class, 'mine']);
        Route::get   ('/missions/{id}/tasks',                [MissionTaskController::class, 'index']);
        // Toewijsbare personen + (eventueel) gekoppeld team voor de moderne
        // multi-select. Staat vóór /{taskId} zodat 'assignees' nooit als een
        // taak-ID wordt gelezen.
        Route::get   ('/missions/{id}/tasks/assignees',      [MissionTaskController::class, 'assignees']);
        Route::post  ('/missions/{id}/tasks',                [MissionTaskController::class, 'store']);
        Route::put   ('/missions/{id}/tasks/{taskId}',       [MissionTaskController::class, 'update']);
        Route::delete('/missions/{id}/tasks/{taskId}',       [MissionTaskController::class, 'destroy']);
        Route::post  ('/missions/{id}/tasks/reorder',        [MissionTaskController::class, 'reorder']);
        Route::post  ('/missions/{id}/tasks/apply-template', [MissionTaskController::class, 'applyTemplate']);

        // ── Mission task templates (per-gebruiker herbruikbaar) ─────
        Route::get   ('/task-templates',         [MissionTaskTemplateController::class, 'index']);
        Route::post  ('/task-templates',         [MissionTaskTemplateController::class, 'store']);
        Route::get   ('/task-templates/{id}',    [MissionTaskTemplateController::class, 'show']);
        Route::put   ('/task-templates/{id}',    [MissionTaskTemplateController::class, 'update']);
        Route::delete('/task-templates/{id}',    [MissionTaskTemplateController::class, 'destroy']);

        // ── O-group templates (fase-builder; per-account, optioneel gedeeld) ──
        Route::get   ('/ogroup-templates',       [OgroupTemplateController::class, 'index']);
        Route::post  ('/ogroup-templates',       [OgroupTemplateController::class, 'store']);
        Route::get   ('/ogroup-templates/{id}',  [OgroupTemplateController::class, 'show']);
        Route::put   ('/ogroup-templates/{id}',  [OgroupTemplateController::class, 'update']);
        Route::delete('/ogroup-templates/{id}',  [OgroupTemplateController::class, 'destroy']);

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

        // QR-apparaatkoppeling (WhatsApp-Web-patroon): ontgrendel de chat op een
        // nieuw apparaat door met een reeds ontgrendeld apparaat de QR te scannen.
        Route::post('/chat/pairing', [ChatPairingController::class, 'create']);
        Route::post('/chat/pairing/{id}/claim', [ChatPairingController::class, 'claim']);
        Route::get('/chat/pairing/{id}', [ChatPairingController::class, 'show']);

        // Conversations
        Route::get('/chat/conversations', [ConversationController::class, 'index']);
        // Een nieuwe chat starten = premiumfunctie (proef of abonnement).
        Route::post('/chat/conversations', [ConversationController::class, 'store'])
            ->middleware(RequiresPremium::class . ':chat');
        Route::get('/chat/conversations/{id}', [ConversationController::class, 'show']);
        Route::post('/chat/conversations/{id}/read', [ConversationController::class, 'markRead']);
        // "Delete" a chat from the user's own list (per-participant archive).
        // The conversation itself stays alive for the other participants. The
        // archived chat shows up in /trash and can be restored within 60 days.
        Route::delete('/chat/conversations/{id}', [ConversationController::class, 'archive']);
        // Long-press menu — per-user state op de pivot.
        Route::post('/chat/conversations/{id}/mute',         [ConversationController::class, 'mute']);
        Route::post('/chat/conversations/{id}/favorite',     [ConversationController::class, 'favorite']);
        Route::post('/chat/conversations/{id}/mark-unread',  [ConversationController::class, 'markUnread']);
        Route::post('/chat/conversations/{id}/leave',        [ConversationController::class, 'leave']);
        Route::post('/chat/conversations/{id}/clear',        [ConversationController::class, 'clear']);
        Route::post('/chat/conversations/{id}/block',        [ConversationController::class, 'block']);
        // Geblokkeerde gebruikers beheren: lijst opvragen + deblokkeren.
        // User-level (geldt over alle 1-op-1 chats), dus losgekoppeld van een
        // specifieke conversation-id.
        Route::get('/chat/blocked',                          [ConversationController::class, 'blockedList']);
        Route::delete('/chat/blocked/{userId}',              [ConversationController::class, 'unblock']);

        // Messages
        Route::get('/chat/conversations/{id}/messages', [MessageController::class, 'index']);
        // Bericht versturen = premiumfunctie. Lezen blijft mogelijk.
        Route::post('/chat/conversations/{id}/messages', [MessageController::class, 'store'])
            ->middleware(RequiresPremium::class . ':chat');
        // "Verzenden ongedaan maken" — sender unsends (deletes) their own message.
        Route::delete('/chat/conversations/{id}/messages/{messageId}', [MessageController::class, 'destroy']);

        // Message reactions (WhatsApp-style emoji likes)
        Route::post('/chat/conversations/{id}/messages/{messageId}/reactions', [MessageController::class, 'react']);
        Route::delete('/chat/conversations/{id}/messages/{messageId}/reactions', [MessageController::class, 'unreact']);

        // Chat attachments
        Route::post('/chat/attachments', [ChatAttachmentController::class, 'store']);

        // ── Prullenbak (soft-deleted Maps/Missions/RouteMaps + archived chats)
        // 60-day grace window; `php artisan trash:purge` runs nightly. Type-
        // parameter is validated against the controller's match(); other values
        // 422 there. Authorization (owner-only) is enforced per-action.
        // "App data" voor het menu — counts + opslag-totaal van de gebruiker.
        Route::get('/me/stats', [StatsController::class, 'show']);

        // App-lock tripped: 10x verkeerde PIN ingevoerd. Trekt alle tokens
        // in en stuurt een security-mail. Eigen throttle in de controller.
        Route::post('/security/app-lock-tripped', [SecurityController::class, 'appLockTripped']);

        // In-app meldingen (Hub + /meldingen). Mission-complete pusht hier
        // bv. een "vul de debrief in"-rij naar elke deelnemer.
        Route::get   ('/me/notifications',                [AppNotificationController::class, 'index']);
        Route::post  ('/me/notifications/{id}/read',      [AppNotificationController::class, 'markRead']);
        Route::post  ('/me/notifications/read-all',       [AppNotificationController::class, 'markAllRead']);
        Route::delete('/me/notifications/{id}',           [AppNotificationController::class, 'destroy']);
        // In-app weergave van een campagne-mail (push-melding → bericht lezen).
        Route::get   ('/me/campaign-mail/{token}',        [MeCampaignMailController::class, 'show']);

        // Media-beheer: lijst van eigen geüploade bestanden en soft-delete
        // naar de prullenbak. /trash levert de items als type "media".
        Route::get   ('/me/media',         [MediaController::class, 'index']);
        Route::delete('/me/media/{id}',    [MediaController::class, 'destroy']);

        // MEDEVAC 9-liners — persisted overview + edit + audit log + archive.
        // Scope/permissions enforced in the controller (participant-based).
        Route::get('/nine-lines', [NineLineController::class, 'index']);
        Route::post('/nine-lines', [NineLineController::class, 'store'])
            ->middleware(RequiresPremium::class . ':nine_liner');
        Route::get('/nine-lines/{id}', [NineLineController::class, 'show']);
        Route::put('/nine-lines/{id}', [NineLineController::class, 'update']);
        Route::post('/nine-lines/{id}/archive', [NineLineController::class, 'archive']);
        Route::post('/nine-lines/{id}/unarchive', [NineLineController::class, 'unarchive']);

        Route::get('/trash', [TrashController::class, 'index']);
        Route::post('/trash/{type}/{id}/restore', [TrashController::class, 'restore']);
        Route::delete('/trash/{type}/{id}', [TrashController::class, 'forceDelete']);

        // Chat invite link (for e-mails without an account yet)
        Route::post('/chat/invite', [ChatInviteController::class, 'store'])
            ->middleware(['throttle:10,1', RequiresPremium::class . ':chat']);

        // Chat connection requests (existing accounts must accept first)
        Route::post('/chat/requests', [ChatRequestController::class, 'store'])
            ->middleware(['throttle:20,1', RequiresPremium::class . ':chat']);
        Route::get('/chat/requests/incoming', [ChatRequestController::class, 'incoming']);
        // Outgoing pending connections (requests + e-mail invites) → "pending" chats
        Route::get('/chat/pending', [ChatRequestController::class, 'pending']);
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

            // ── SEO-CMS (per milmap.nl-pagina × taal) ───────────────────
            Route::get ('/admin/seo',              [AdminSeoController::class, 'index']);
            Route::post('/admin/seo',              [AdminSeoController::class, 'upsert']);
            Route::post('/admin/seo/generate',     [AdminSeoController::class, 'generate']);
            Route::post('/admin/seo/upload-image', [AdminSeoController::class, 'uploadImage']);

            // In-app notificatie-feed voor de admin-app (nieuwe mail / support /
            // feature requests). Onder de admin-prefix zodat de frontend-
            // interceptor het admin-token meestuurt. Hergebruikt de feed-
            // controller (werkt op de ingelogde admin via Auth::id()).
            Route::get ('/admin/notifications',           [AppNotificationController::class, 'index']);
            Route::post('/admin/notifications/read-all',  [AppNotificationController::class, 'markAllRead']);
            Route::post('/admin/notifications/{id}/read', [AppNotificationController::class, 'markRead']);

            Route::get('/admin/client-errors', [AdminController::class, 'clientErrors']);
            Route::delete('/admin/client-errors', [AdminController::class, 'clearClientErrors']);

            // ── Stripe billing configuration ────────────────────────────
            Route::get('/admin/billing/prices', [AdminBillingController::class, 'prices']);
            Route::get('/admin/billing/price-map', [AdminBillingController::class, 'priceMap']);
            Route::put('/admin/billing/price-map', [AdminBillingController::class, 'savePriceMap']);
            Route::post('/admin/billing/flush-cache', [AdminBillingController::class, 'flushPricingCache']);
            // ── Status-page domains (managed here, shown on milmap.nl/status) ─
            Route::get   ('/admin/status-domains',        [StatusPageController::class, 'adminIndex']);
            Route::post  ('/admin/status-domains',        [StatusPageController::class, 'store']);
            Route::post  ('/admin/status-domains/check',  [StatusPageController::class, 'check']);
            Route::put   ('/admin/status-domains/{id}',   [StatusPageController::class, 'update']);
            Route::delete('/admin/status-domains/{id}',   [StatusPageController::class, 'destroy']);

            // ── Leads (marketing signups via milmap.nl/app) ─────────────
            Route::get   ('/admin/leads',                  [LeadController::class, 'adminIndex']);
            Route::post  ('/admin/leads/{id}/mark-notified', [LeadController::class, 'adminMarkNotified']);
            Route::post  ('/admin/leads/{id}/send-download-mail', [LeadController::class, 'adminSendDownloadMail']);
            Route::delete('/admin/leads/{id}',              [LeadController::class, 'adminDestroy']);

            // ── Agenda (geplande mails/campagnes, verzonden, leads + eigen afspraken) ─
            Route::get   ('/admin/agenda/events',            [AdminAgendaController::class, 'events']);
            Route::post  ('/admin/agenda/appointments',      [AdminAgendaController::class, 'store']);
            Route::put   ('/admin/agenda/appointments/{id}', [AdminAgendaController::class, 'update']);
            Route::delete('/admin/agenda/appointments/{id}', [AdminAgendaController::class, 'destroy']);

            // ── E-mailcampagnes ─────────────────────────────────────────
            // Template-registry (door Claude geschreven backend-templates).
            Route::get ('/admin/mail/templates',                 [MailCampaignController::class, 'templates']);
            Route::get ('/admin/mail/templates/{key}/preview',   [MailCampaignController::class, 'previewTemplate']);
            Route::post('/admin/mail/templates/{key}/test',      [MailCampaignController::class, 'testTemplate']);
            // Zelf opgemaakte templates (blok-builder) — zelfde fundering/registry.
            Route::post  ('/admin/mail/custom-templates/upload-image', [MailCampaignController::class, 'uploadImage']);
            Route::post  ('/admin/mail/custom-templates/preview',      [MailCampaignController::class, 'customPreview']);
            Route::get   ('/admin/mail/custom-templates/{id}',         [MailCampaignController::class, 'customShow']);
            Route::post  ('/admin/mail/custom-templates',              [MailCampaignController::class, 'customStore']);
            Route::put   ('/admin/mail/custom-templates/{id}',         [MailCampaignController::class, 'customUpdate']);
            Route::delete('/admin/mail/custom-templates/{id}',         [MailCampaignController::class, 'customDestroy']);
            // Unified contact-zoek (gebruikers + leads) voor de ontvanger-picker.
            Route::get ('/admin/mail/contacts',                  [MailCampaignController::class, 'contacts']);
            // Categorieën (de "soort" mail waar contacten zich per stuk voor afmelden).
            Route::get   ('/admin/mail-categories',       [MailCampaignController::class, 'categories']);
            Route::post  ('/admin/mail-categories',       [MailCampaignController::class, 'storeCategory']);
            Route::put   ('/admin/mail-categories/{id}',  [MailCampaignController::class, 'updateCategory']);
            Route::delete('/admin/mail-categories/{id}',  [MailCampaignController::class, 'destroyCategory']);
            // Campagnes + ontvangers + verzenden + log.
            Route::get   ('/admin/campaigns',                       [MailCampaignController::class, 'index']);
            Route::post  ('/admin/campaigns',                       [MailCampaignController::class, 'store']);
            Route::get   ('/admin/campaigns/{id}',                  [MailCampaignController::class, 'show']);
            Route::put   ('/admin/campaigns/{id}',                  [MailCampaignController::class, 'update']);
            Route::delete('/admin/campaigns/{id}',                  [MailCampaignController::class, 'destroy']);
            Route::get   ('/admin/campaigns/{id}/recipients',       [MailCampaignController::class, 'recipients']);
            Route::post  ('/admin/campaigns/{id}/recipients',       [MailCampaignController::class, 'addRecipients']);
            Route::delete('/admin/campaigns/{id}/recipients/{rid}', [MailCampaignController::class, 'removeRecipient']);
            Route::post  ('/admin/campaigns/{id}/leads',            [MailCampaignController::class, 'addLead']);
            Route::post  ('/admin/campaigns/{id}/send',             [MailCampaignController::class, 'send']);
            Route::get   ('/admin/campaigns/{id}/stats',            [MailCampaignController::class, 'stats']);
            Route::get   ('/admin/campaigns/{id}/sends',            [MailCampaignController::class, 'sends']);
            // Follow-up-regels (stuur mail X na N dagen o.b.v. een voorwaarde).
            Route::get   ('/admin/campaigns/{id}/followups',          [MailCampaignController::class, 'followups']);
            Route::post  ('/admin/campaigns/{id}/followups',          [MailCampaignController::class, 'storeFollowup']);
            Route::post  ('/admin/campaigns/{id}/followups/reorder',  [MailCampaignController::class, 'reorderFollowups']);
            Route::put   ('/admin/campaigns/{id}/followups/{fid}',    [MailCampaignController::class, 'updateFollowup']);
            Route::delete('/admin/campaigns/{id}/followups/{fid}',    [MailCampaignController::class, 'destroyFollowup']);

            // ── Feature requests (publiek formulier op milmap.nl) ───────
            Route::get   ('/admin/feature-requests',      [FeatureRequestController::class, 'adminIndex']);
            Route::put   ('/admin/feature-requests/{id}', [FeatureRequestController::class, 'adminUpdate']);
            Route::delete('/admin/feature-requests/{id}', [FeatureRequestController::class, 'adminDestroy']);

            // ── Deploy-todo's (gedeeld met de deploy-app) ───────────────
            Route::get   ('/admin/todos',      [TodoController::class, 'adminIndex']);
            Route::post  ('/admin/todos',      [TodoController::class, 'adminStore']);
            Route::put   ('/admin/todos/{id}', [TodoController::class, 'adminUpdate']);
            Route::delete('/admin/todos/{id}', [TodoController::class, 'adminDestroy']);

            Route::get('/admin/users/{userId}', [AdminController::class, 'getUser']);
            Route::delete('/admin/users/{userId}', [AdminController::class, 'deleteUser']);
            Route::post('/admin/users/{userId}/reset-password', [AdminController::class, 'resetUserPassword']);
            // Gratis (premium-)toegang toekennen/verlengen + handmatig e-mail verifiëren.
            Route::post('/admin/users/{userId}/grant-access', [AdminController::class, 'grantAccess']);
            Route::post('/admin/users/{userId}/verify-email', [AdminController::class, 'verifyUserEmail']);

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


/*
 * Deploy-app endpoints — headless toegang met een gedeeld geheim
 * (header: X-Deploy-Token), buiten Sanctum om. Zelfde tabel als /admin/todos.
 */
Route::prefix('v1')->middleware(['api', 'deploy.token'])->group(function () {
    Route::get   ('/deploy/todos',      [TodoController::class, 'deployIndex']);
    Route::post  ('/deploy/todos',      [TodoController::class, 'deployStore']);
    Route::put   ('/deploy/todos/{id}', [TodoController::class, 'deployUpdate']);
    Route::delete('/deploy/todos/{id}', [TodoController::class, 'deployDestroy']);
});