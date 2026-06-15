<?php

use App\Http\Controllers\GitCredentialController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('git-credentials', [GitCredentialController::class, 'index'])->name('git-credentials.index');
    Route::post('git-credentials', [GitCredentialController::class, 'store'])->name('git-credentials.store');
    Route::delete('git-credentials/{gitCredential}', [GitCredentialController::class, 'destroy'])->name('git-credentials.destroy');
});

// OAuth — redirect-based, no CSRF token needed on GET
Route::middleware('auth')->group(function () {
    Route::get('git-credentials/oauth/{provider}/redirect', [\App\Http\Controllers\GitOAuthController::class, 'redirect'])
        ->name('git-credentials.oauth.redirect');
    Route::get('git-credentials/oauth/{provider}/callback', [\App\Http\Controllers\GitOAuthController::class, 'callback'])
        ->name('git-credentials.oauth.callback');
});
