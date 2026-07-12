<?php

use Illuminate\Support\Facades\Route;

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

