<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');


use App\Http\Controllers\WorkSpaceController;

// Route::get('/workspace/invite/accept/{id}', [WorkSpaceController::class, 'acceptInvite'])->name('workspace.invite.accept');
// Route::get('/workspace/invite/decline/{id}', [WorkSpaceController::class, 'declineInvite'])->name('workspace.invite.decline');
// Route::get('/workspace/invite/register/{id}', [WorkSpaceController::class, 'registerInvite'])->name('workspace.register.decline');

