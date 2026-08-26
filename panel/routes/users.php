<?php

use App\Http\Controllers\Auth\InvitationAcceptController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['panel', 'admin'])->group(function () {
    Route::get('settings/users', [UserController::class, 'index'])->name('users.index');
    Route::patch('settings/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::put('settings/users/{user}/servers', [UserController::class, 'syncServers'])->name('users.servers.sync');
    Route::delete('settings/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::post('settings/users/invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::delete('settings/users/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
});

// Invite acceptance is deliberately outside RegistrationEnabled and the
// 'panel' group — a guest with a valid token must be able to reach it.
Route::middleware('guest')->group(function () {
    Route::get('invitations/{token}', [InvitationAcceptController::class, 'create'])->name('invitations.accept');
    Route::post('invitations/{token}', [InvitationAcceptController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('invitations.accept.store');
});
