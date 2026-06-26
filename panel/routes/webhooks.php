<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Throttle prevents brute-force signature/token guessing and webhook hammering.
Route::middleware('throttle:60,1')->group(function () {
    Route::post('webhooks/github/{application}', [WebhookController::class, 'github'])
        ->name('webhooks.github');

    Route::post('webhooks/gitlab/{application}', [WebhookController::class, 'gitlab'])
        ->name('webhooks.gitlab');
});
