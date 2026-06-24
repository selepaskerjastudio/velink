<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
    Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::get('servers/{server}/connect', [ServerController::class, 'connect'])->name('servers.connect');
    Route::get('servers/{server}/monitoring', [ServerController::class, 'monitoring'])->name('servers.monitoring');
    Route::get('servers/{server}/settings', [ServerController::class, 'settings'])->name('servers.settings');
    Route::get('servers/{server}/ssh-keys', [ServerController::class, 'sshKeys'])->name('servers.ssh-keys');
    Route::get('servers/{server}/activity', [AuditLogController::class, 'serverIndex'])->name('servers.activity');
    Route::post('servers/{server}/provision', [ProvisioningController::class, 'store'])->name('servers.provision');
    Route::patch('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
    Route::post('servers/{server}/restart', [ServerController::class, 'restart'])->name('servers.restart');
    Route::post('servers/{server}/regenerate-token', [ServerController::class, 'regenerateToken'])->name('servers.regenerate-token');
    Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
});
