<?php

use Illuminate\Support\Facades\Route;

// Named route 'login' — MOET bestaan omdat Laravel's Authenticate-middleware
// bij een niet-geauthenticeerd, niet-JSON request `route('login')` aanroept.
// Zonder deze route gooit dat "Route [login] not defined." (een
// RouteNotFoundException die de AuthenticationException-handler in
// bootstrap/app.php níét kan vangen, want hij ontstaat al tijdens het opbouwen
// van de redirect-URL in de middleware). We zijn een API-only backend, dus we
// antwoorden hier gewoon met 401 JSON i.p.v. een inlog-pagina. Staat bewust
// vóór de SPA-catch-all hieronder, anders slokt /{any} het request op.
Route::get('/login', fn () => response()->json(['message' => 'Unauthenticated.'], 401))
    ->name('login');

// SPA-fallback: serveert de Vue-app-shell voor alle "echte" routes.
// Belangrijk: verzoeken naar statische assets (.js, .css, .map, images, fonts…)
// worden UITGESLOTEN. Bestaat zo'n asset niet meer (bv. een gehashte bundle van
// een oude build na een deploy), dan hoort de browser een echte 404 te krijgen —
// niet de HTML-shell met status 200. Anders parseert de browser HTML als JS en
// ontstaat: "Uncaught SyntaxError: Unexpected token '<'".
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '(?!.*\.(?:js|mjs|css|map|json|ico|png|jpe?g|gif|svg|webp|avif|woff2?|ttf|otf|eot|txt|xml|wasm)$).*');


use App\Http\Controllers\WorkSpaceController;

// Route::get('/workspace/invite/accept/{id}', [WorkSpaceController::class, 'acceptInvite'])->name('workspace.invite.accept');
// Route::get('/workspace/invite/decline/{id}', [WorkSpaceController::class, 'declineInvite'])->name('workspace.invite.decline');
// Route::get('/workspace/invite/register/{id}', [WorkSpaceController::class, 'registerInvite'])->name('workspace.register.decline');

