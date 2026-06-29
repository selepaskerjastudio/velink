<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\DeploymentLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/applications', [ApplicationController::class, 'serverIndex'])->name('applications.server-index');
    Route::get('servers/{server}/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
    Route::post('servers/{server}/applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::get('apps/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::delete('apps/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy');
    Route::patch('apps/{application}/php-version', [ApplicationController::class, 'updatePhpVersion'])->name('applications.php-version');
    Route::patch('apps/{application}/env', [ApplicationController::class, 'updateEnv'])->name('applications.env');
    Route::patch('apps/{application}/deploy-settings', [ApplicationController::class, 'updateDeploySettings'])->name('applications.deploy-settings');
    Route::patch('apps/{application}/domain', [ApplicationController::class, 'updateDomain'])->name('applications.domain');
    Route::post('apps/{application}/deployments', [ApplicationController::class, 'storeDeployment'])->name('applications.deployments.store');
    Route::post('apps/{application}/ssl', [ApplicationController::class, 'enableSsl'])->name('applications.ssl');
    Route::post('apps/{application}/nginx-config', [ApplicationController::class, 'nginxConfig'])->name('applications.nginx-config');
    Route::post('apps/{application}/directory-size', [ApplicationController::class, 'refreshDirectorySize'])->name('applications.directory-size');

    // Dedicated full-page deployment log viewer (ANSI-rendered, realtime).
    // Flat binding on {deployment} — the application is resolved via the
    // deployment relation. (Nested two-model binding has a Laravel routing
    // quirk that fails to resolve the child; the flat form is robust.)
    Route::get('deployments/{deployment}/log', [DeploymentLogController::class, 'show'])
        ->name('deployments.log');
});
