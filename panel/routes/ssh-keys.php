<?php

use App\Http\Controllers\ServerSshKeyController;
use App\Http\Controllers\SshKeyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Account-scoped SSH key management (add/remove your keys).
    Route::get('settings/ssh-keys', [SshKeyController::class, 'index'])->name('ssh-keys.index');
    Route::post('settings/ssh-keys', [SshKeyController::class, 'store'])->name('ssh-keys.store');
    Route::delete('settings/ssh-keys/{sshKey}', [SshKeyController::class, 'destroy'])->name('ssh-keys.destroy');

    // Per-server deployment (deploy/revoke a key to a specific server's authorized_keys).
    Route::post('servers/{server}/ssh-keys/{sshKey}/deploy', [ServerSshKeyController::class, 'deploy'])
        ->name('server.ssh-keys.deploy');
    Route::delete('servers/{server}/ssh-keys/{sshKey}', [ServerSshKeyController::class, 'revoke'])
        ->name('server.ssh-keys.revoke');
});
