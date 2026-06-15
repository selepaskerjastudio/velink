<?php

use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/services', [ServiceController::class, 'index'])->name('services.index');
    Route::post('servers/{server}/services', [ServiceController::class, 'store'])->name('services.store');
    Route::post('services/{service}/control', [ServiceController::class, 'control'])->name('services.control');
    Route::post('services/{service}/refresh-status', [ServiceController::class, 'refreshStatus'])->name('services.refresh-status');
    Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
});
