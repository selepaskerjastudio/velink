<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/github/{application}', [WebhookController::class, 'github'])
    ->name('webhooks.github');
