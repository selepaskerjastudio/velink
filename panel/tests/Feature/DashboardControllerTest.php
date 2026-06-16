<?php

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Helper: call an Inertia controller and extract props WITHOUT rendering Blade.
 */
function dashboardProps(): array
{
    $controller = app(\App\Http\Controllers\DashboardController::class);

    // Use reflection to build the Inertia response, then grab props
    // via the component/data properties (avoids Vite/Blade rendering).
    $response = $controller->__invoke();

    // Inertia\Response stores props internally — access via shared data
    // We use the LazyHeaders test helper or access props via Inertia's shared.
    // Simplest: serialize the Inertia response props directly.
    $reflection = new \ReflectionClass($response);
    $propsProp = $reflection->getProperty('props');
    $propsProp->setAccessible(true);
    $rawProps = $propsProp->getValue($response);

    // Resolve any closures
    $resolved = [];
    foreach ($rawProps as $key => $value) {
        $resolved[$key] = $value instanceof \Closure ? $value() : $value;
    }

    return $resolved;
}

// ──────────────────────────────────────────────────────
// Dashboard: server overview
// ──────────────────────────────────────────────────────

test('dashboard returns all servers with latest metric', function () {
    $server = Server::factory()->online()->create();

    ServerMetric::create([
        'server_id' => $server->id,
        'cpu_percent' => 45.0,
        'mem_total' => 2048,
        'mem_used' => 1024,
        'disk_total' => 4096,
        'disk_used' => 2048,
        'load1' => 1.5,
        'recorded_at' => now(),
    ]);

    $props = dashboardProps();

    expect($props)->toHaveKey('servers');
    expect($props['servers'])->toHaveCount(1);

    $srv = $props['servers'][0];
    expect($srv['cpu_percent'])->toBe(45.0);
    expect($srv['mem_used'])->toBe(1024);
    expect($srv['mem_total'])->toBe(2048);
    expect($srv['disk_used'])->toBe(2048);
    expect($srv['disk_total'])->toBe(4096);
    expect($srv['load1'])->toBe(1.5);
});

test('dashboard server without metrics shows null values', function () {
    Server::factory()->online()->create();

    $props = dashboardProps();

    $srv = $props['servers'][0];
    expect($srv['cpu_percent'])->toBeNull();
    expect($srv['mem_used'])->toBeNull();
});

test('dashboard returns recent audit logs (latest 10)', function () {
    $user = User::factory()->create();

    foreach (range(1, 12) as $i) {
        AuditLog::create([
            'action' => "test.action.{$i}",
            'description' => "Test log entry {$i}",
            'user_id' => $user->id,
        ]);
    }

    $props = dashboardProps();

    expect($props['recentActivity'])->toHaveCount(10);
    expect($props['recentActivity'][0]['action'])->toBe('test.action.12');
});

test('dashboard returns recent deployments (latest 5)', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create(['server_id' => $server->id]);

    foreach (range(1, 7) as $i) {
        Deployment::create([
            'application_id' => $app->id,
            'user_id' => $user->id,
            'branch' => 'main',
            'mode' => 'in_place',
            'status' => $i <= 5 ? 'succeeded' : 'running',
            'triggered_by' => 'manual',
            'started_at' => now(),
        ]);
    }

    $props = dashboardProps();

    expect($props['recentDeployments'])->toHaveCount(5);
});

test('dashboard counts servers by status', function () {
    Server::factory()->online()->create();
    Server::factory()->online()->create();
    Server::factory()->state(['status' => 'offline'])->create();
    Server::factory()->state(['status' => 'provisioning'])->create();

    $props = dashboardProps();

    expect($props['serverCounts'])->toBe([
        'total' => 4,
        'online' => 2,
        'offline' => 1,
        'provisioning' => 1,
    ]);
});
