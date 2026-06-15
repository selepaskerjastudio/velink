<?php

use App\Http\Controllers\GitCredentialController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('git-credentials', [GitCredentialController::class, 'index'])->name('git-credentials.index');
    Route::post('git-credentials', [GitCredentialController::class, 'store'])->name('git-credentials.store');
    Route::delete('git-credentials/{gitCredential}', [GitCredentialController::class, 'destroy'])->name('git-credentials.destroy');
});
