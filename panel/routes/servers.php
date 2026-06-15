<?php

use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
    Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::post('servers/{server}/provision', [ProvisioningController::class, 'store'])->name('servers.provision');
    Route::post('servers/{server}/regenerate-token', [ServerController::class, 'regenerateToken'])->name('servers.regenerate-token');
});
