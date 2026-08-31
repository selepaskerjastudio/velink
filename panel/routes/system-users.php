<?php

use App\Http\Controllers\SystemUserController;
use Illuminate\Support\Facades\Route;

// Administrator-only in full: creating an OS account, and especially toggling
// its sudo flag, grants root-equivalent access to the server.
Route::middleware(['panel', 'admin'])->group(function () {
    Route::get('servers/{server}/system-users', [SystemUserController::class, 'index'])->name('system-users.index');
    Route::post('servers/{server}/system-users', [SystemUserController::class, 'store'])->name('system-users.store');
    Route::patch('system-users/{systemUser}/sudo', [SystemUserController::class, 'updateSudo'])->name('system-users.sudo');
    Route::patch('system-users/{systemUser}/shell', [SystemUserController::class, 'updateShell'])->name('system-users.shell');
    Route::delete('system-users/{systemUser}', [SystemUserController::class, 'destroy'])->name('system-users.destroy');
});
