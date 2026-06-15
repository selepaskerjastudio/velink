<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\GitHubRepoController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('install/agent.sh', function () {
    $path = base_path('../installer/agent.sh');
    abort_unless(file_exists($path), 404);
    return response()->file($path, ['Content-Type' => 'text/plain; charset=utf-8']);
})->name('install.agent');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('github/repos', [GitHubRepoController::class, 'search'])->name('github.repos');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/servers.php';
require __DIR__.'/applications.php';
require __DIR__.'/git-credentials.php';
require __DIR__.'/workers.php';
require __DIR__.'/services.php';
require __DIR__.'/cron.php';
require __DIR__.'/databases.php';
require __DIR__.'/database-users.php';
