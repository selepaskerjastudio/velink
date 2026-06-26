<?php

use App\Http\Controllers\BackupController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('apps/{application}/backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('apps/{application}/backups', [BackupController::class, 'store'])->name('backups.store');
    Route::post('apps/{application}/backups/settings', [BackupController::class, 'updateSettings'])->name('backups.settings');
    Route::post('apps/{application}/backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
    Route::delete('apps/{application}/backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');
});
