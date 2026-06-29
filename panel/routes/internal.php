<?php

use App\Http\Controllers\Internal\AgentVerificationController;
use App\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

/*
| Internal API consumed by the Go gateway. Authenticated by a shared secret
| (see App\Http\Middleware\VerifyGatewaySecret); no session/CSRF applies.
*/

Route::post('agent/verify', [AgentVerificationController::class, 'verify'])->name('internal.agent.verify');
Route::post('terminal/auth', [TerminalController::class, 'auth'])->name('internal.terminal.auth');
