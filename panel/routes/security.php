<?php

use App\Http\Controllers\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('servers/{server}/security', [SecurityController::class, 'index'])->name('security.index');
    Route::post('servers/{server}/security/firewall/rules', [SecurityController::class, 'storeRule'])->name('security.firewall.store');
    Route::delete('servers/{server}/security/firewall/{rule}', [SecurityController::class, 'destroyRule'])->name('security.firewall.destroy');
    Route::post('servers/{server}/security/fail2ban/install', [SecurityController::class, 'installFail2Ban'])->name('security.fail2ban.install');
    Route::post('servers/{server}/security/fail2ban/ban', [SecurityController::class, 'banIp'])->name('security.fail2ban.ban');
    Route::delete('servers/{server}/security/fail2ban/{ip}', [SecurityController::class, 'unbanIp'])->name('security.fail2ban.unban');
});
