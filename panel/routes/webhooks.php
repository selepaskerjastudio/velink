<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/github/{application}', [WebhookController::class, 'github'])
    ->name('webhooks.github');

Route::post('webhooks/gitlab/{application}', [WebhookController::class, 'gitlab'])
    ->name('webhooks.gitlab');
