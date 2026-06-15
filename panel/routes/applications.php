<?php

use App\Http\Controllers\ApplicationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
    Route::post('servers/{server}/applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::patch('applications/{application}/php-version', [ApplicationController::class, 'updatePhpVersion'])->name('applications.php-version');
    Route::patch('applications/{application}/env', [ApplicationController::class, 'updateEnv'])->name('applications.env');
    Route::patch('applications/{application}/deploy-settings', [ApplicationController::class, 'updateDeploySettings'])->name('applications.deploy-settings');
    Route::post('applications/{application}/deployments', [ApplicationController::class, 'storeDeployment'])->name('applications.deployments.store');
});
