<?php

use App\Http\Controllers\CronJobController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/cron', [CronJobController::class, 'index'])->name('cron.index');
    Route::post('servers/{server}/cron', [CronJobController::class, 'store'])->name('cron.store');
    Route::patch('cron/{cronJob}', [CronJobController::class, 'update'])->name('cron.update');
    Route::post('cron/{cronJob}/toggle', [CronJobController::class, 'toggle'])->name('cron.toggle');
    Route::delete('cron/{cronJob}', [CronJobController::class, 'destroy'])->name('cron.destroy');
});
