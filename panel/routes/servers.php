<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

// Administrator-only routes are marked inline rather than grouped: 'servers/create'
// must stay registered before 'servers/{server}', or the latter would match it
// first and fail binding on uuid='create'. Inline middleware keeps the order.
Route::middleware('panel')->group(function () {
    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');

    // Admin-only: a member could not see a server they just created.
    Route::get('servers/create', [ServerController::class, 'create'])->middleware('admin')->name('servers.create');
    Route::post('servers', [ServerController::class, 'store'])->middleware('admin')->name('servers.store');

    Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::get('servers/{server}/connect', [ServerController::class, 'connect'])->name('servers.connect');
    Route::get('servers/{server}/monitoring', [ServerController::class, 'monitoring'])->name('servers.monitoring');
    Route::get('servers/{server}/settings', [ServerController::class, 'settings'])->name('servers.settings');
    Route::get('servers/{server}/ssh-keys', [ServerController::class, 'sshKeys'])->name('servers.ssh-keys');
    Route::get('servers/{server}/activity', [AuditLogController::class, 'serverIndex'])->name('servers.activity');
    Route::patch('servers/{server}', [ServerController::class, 'update'])->name('servers.update');

    // Admin-only: root shell.
    Route::get('servers/{server}/terminal', [TerminalController::class, 'show'])->middleware('admin')->name('servers.terminal');

    // Admin-only: server lifecycle. A reboot takes down every application on the
    // box, including those belonging to other members assigned to it.
    Route::post('servers/{server}/provision', [ProvisioningController::class, 'store'])->middleware('admin')->name('servers.provision');
    Route::post('servers/{server}/restart', [ServerController::class, 'restart'])->middleware('admin')->name('servers.restart');
    Route::post('servers/{server}/regenerate-token', [ServerController::class, 'regenerateToken'])->middleware('admin')->name('servers.regenerate-token');
    Route::delete('servers/{server}', [ServerController::class, 'destroy'])->middleware('admin')->name('servers.destroy');
});
