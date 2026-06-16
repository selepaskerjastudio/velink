<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GitHubRepoController;
use App\Http\Controllers\ServerAlertController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('dashboard');
})->name('home');

Route::get('install/agent.sh', function () {
    $path = base_path('../installer/agent.sh');
    abort_unless(file_exists($path), 404);
    return response()->file($path, ['Content-Type' => 'text/plain; charset=utf-8']);
})->name('install.agent');

Route::get('install/bin/{file}', function (string $file) {
    abort_unless(preg_match('/^agent-[a-z0-9]+-[a-z0-9]+-[a-z0-9.]+$/', $file), 404);
    $path = storage_path("app/agent-bins/{$file}");
    abort_unless(file_exists($path), 404);
    return response()->download($path, $file, ['Content-Type' => 'application/octet-stream']);
})->name('install.binary');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('github/repos', [GitHubRepoController::class, 'search'])->name('github.repos');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::get('alerts', [ServerAlertController::class, 'index'])->name('alerts.index');
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
