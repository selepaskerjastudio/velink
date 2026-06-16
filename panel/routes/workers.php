<?php

use App\Http\Controllers\WorkerController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/workers', [WorkerController::class, 'serverIndex'])->name('servers.workers');
    Route::get('apps/{application}/workers', [WorkerController::class, 'index'])->name('workers.index');
    Route::post('apps/{application}/workers', [WorkerController::class, 'store'])->name('workers.store');
    Route::patch('workers/{worker}', [WorkerController::class, 'update'])->name('workers.update');
    Route::post('workers/{worker}/control', [WorkerController::class, 'control'])->name('workers.control');
    Route::delete('workers/{worker}', [WorkerController::class, 'destroy'])->name('workers.destroy');
});
