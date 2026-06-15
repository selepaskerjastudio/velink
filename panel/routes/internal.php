<?php

use App\Http\Controllers\Internal\AgentVerificationController;
use Illuminate\Support\Facades\Route;

/*
| Internal API consumed by the Go gateway. Authenticated by a shared secret
| (see App\Http\Middleware\VerifyGatewaySecret); no session/CSRF applies.
*/

Route::post('agent/verify', [AgentVerificationController::class, 'verify'])->name('internal.agent.verify');
