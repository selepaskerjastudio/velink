<?php

use App\Http\Controllers\SystemUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/system-users', [SystemUserController::class, 'index'])->name('system-users.index');
    Route::post('servers/{server}/system-users', [SystemUserController::class, 'store'])->name('system-users.store');
    Route::patch('system-users/{systemUser}/sudo', [SystemUserController::class, 'updateSudo'])->name('system-users.sudo');
    Route::patch('system-users/{systemUser}/shell', [SystemUserController::class, 'updateShell'])->name('system-users.shell');
    Route::delete('system-users/{systemUser}', [SystemUserController::class, 'destroy'])->name('system-users.destroy');
});
