<?php

use App\Http\Controllers\NotificationChannelController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('settings/notifications', [NotificationChannelController::class, 'index'])->name('notifications.index');
    Route::post('settings/notifications', [NotificationChannelController::class, 'store'])->name('notifications.store');
    Route::patch('settings/notifications/{notificationChannel}/toggle', [NotificationChannelController::class, 'toggle'])->name('notifications.toggle');
    Route::delete('settings/notifications/{notificationChannel}', [NotificationChannelController::class, 'destroy'])->name('notifications.destroy');
});
