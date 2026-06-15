<?php

use App\Http\Controllers\DatabaseUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/database-users', [DatabaseUserController::class, 'index'])->name('database-users.index');
    Route::post('servers/{server}/database-users', [DatabaseUserController::class, 'store'])->name('database-users.store');
    Route::patch('database-users/{databaseUser}/grants', [DatabaseUserController::class, 'grants'])->name('database-users.grants');
    Route::delete('database-users/{databaseUser}', [DatabaseUserController::class, 'destroy'])->name('database-users.destroy');
});
