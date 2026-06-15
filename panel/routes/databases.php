<?php

use App\Http\Controllers\DatabaseInstanceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/databases', [DatabaseInstanceController::class, 'index'])->name('databases.index');
    Route::post('servers/{server}/databases', [DatabaseInstanceController::class, 'store'])->name('databases.store');
    Route::delete('databases/{database}', [DatabaseInstanceController::class, 'destroy'])->name('databases.destroy');
});
