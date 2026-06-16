<?php

use App\Http\Controllers\ApplicationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/applications', [ApplicationController::class, 'serverIndex'])->name('applications.server-index');
    Route::get('servers/{server}/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
    Route::post('servers/{server}/applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::get('apps/{application}', [ApplicationController::class, 'show'])->name('applications.show');
    Route::patch('apps/{application}/php-version', [ApplicationController::class, 'updatePhpVersion'])->name('applications.php-version');
    Route::patch('apps/{application}/env', [ApplicationController::class, 'updateEnv'])->name('applications.env');
    Route::patch('apps/{application}/deploy-settings', [ApplicationController::class, 'updateDeploySettings'])->name('applications.deploy-settings');
    Route::post('apps/{application}/deployments', [ApplicationController::class, 'storeDeployment'])->name('applications.deployments.store');
    Route::post('apps/{application}/ssl', [ApplicationController::class, 'enableSsl'])->name('applications.ssl');
    Route::post('apps/{application}/nginx-config', [ApplicationController::class, 'nginxConfig'])->name('applications.nginx-config');
});
