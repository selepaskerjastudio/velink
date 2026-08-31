<?php

use App\Http\Controllers\ServerSshKeyController;
use App\Http\Controllers\SshKeyController;
use Illuminate\Support\Facades\Route;

Route::middleware('panel')->group(function () {
    // Account-scoped SSH key management (add/remove your keys).
    Route::get('settings/ssh-keys', [SshKeyController::class, 'index'])->name('ssh-keys.index');
    Route::post('settings/ssh-keys', [SshKeyController::class, 'store'])->name('ssh-keys.store');
    Route::delete('settings/ssh-keys/{sshKey}', [SshKeyController::class, 'destroy'])->name('ssh-keys.destroy');

    // Per-server deployment (deploy/revoke a key to a specific server's authorized_keys).
    //
    // Administrator-only: resolveTargetUser() falls back to the velink-admin
    // account, so a member able to deploy their own key here could SSH in as
    // that user — which would make the web-terminal restriction meaningless.
    Route::post('servers/{server}/ssh-keys/{sshKey}/deploy', [ServerSshKeyController::class, 'deploy'])
        ->middleware('admin')
        ->name('server.ssh-keys.deploy');
    Route::delete('servers/{server}/ssh-keys/{sshKey}', [ServerSshKeyController::class, 'revoke'])
        ->middleware('admin')
        ->name('server.ssh-keys.revoke');
});
