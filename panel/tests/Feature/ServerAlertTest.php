<?php

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\ServerAlert;
use App\Services\ThresholdChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ──────────────────────────────────────────────────────
// ServerAlert model basics
// ──────────────────────────────────────────────────────

test('can create a server alert', function () {
    $server = Server::factory()->online()->create();

    $alert = ServerAlert::create([
        'server_id' => $server->id,
        'metric_type' => 'cpu',
        'value' => 95.0,
        'threshold' => 90.0,
        'message' => 'CPU usage at 95.0% (threshold: 90%)',
    ]);

    expect($alert)->toBeInstanceOf(ServerAlert::class);
    expect($alert->metric_type)->toBe('cpu');
    expect($alert->value)->toBe(95.0);
    expect($alert->resolved_at)->toBeNull();
    expect($alert->is_resolved)->toBeFalse();
});

test('alert is considered resolved when resolved_at is set', function () {
    $server = Server::factory()->online()->create();

    $alert = ServerAlert::create([
        'server_id' => $server->id,
        'metric_type' => 'cpu',
        'value' => 95.0,
        'threshold' => 90.0,
        'message' => 'CPU alert',
        'resolved_at' => now(),
    ]);

    expect($alert->is_resolved)->toBeTrue();
});

// ──────────────────────────────────────────────────────
// ThresholdChecker: triggers alert when metric exceeds threshold
// ──────────────────────────────────────────────────────

test('threshold checker creates alert when cpu exceeds 90%', function () {
    $server = Server::factory()->online()->create();

    $checker = new ThresholdChecker;
    $checker->check($server, [
        'cpu_percent' => 95.0,
        'mem_total' => 2048,
        'mem_used' => 1024,
        'disk_total' => 4096,
        'disk_used' => 2048,
        'load1' => 1.0,
    ]);

    $alerts = ServerAlert::where('server_id', $server->id)->active()->get();
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->metric_type)->toBe('cpu');
    expect($alerts->first()->value)->toBe(95.0);
});

test('threshold checker does not create alert when metrics are normal', function () {
    $server = Server::factory()->online()->create();

    $checker = new ThresholdChecker;
    $checker->check($server, [
        'cpu_percent' => 45.0,
        'mem_total' => 2048,
        'mem_used' => 800,
        'disk_total' => 4096,
        'disk_used' => 1000,
        'load1' => 0.5,
    ]);

    expect(ServerAlert::where('server_id', $server->id)->active()->count())->toBe(0);
});

test('threshold checker resolves alert when metric drops below threshold', function () {
    $server = Server::factory()->online()->create();

    // Create existing CPU alert
    ServerAlert::create([
        'server_id' => $server->id,
        'metric_type' => 'cpu',
        'value' => 95.0,
        'threshold' => 90.0,
        'message' => 'CPU alert',
    ]);

    $checker = new ThresholdChecker;
    $checker->check($server, [
        'cpu_percent' => 50.0,
        'mem_total' => 2048,
        'mem_used' => 800,
        'disk_total' => 4096,
        'disk_used' => 1000,
        'load1' => 0.5,
    ]);

    // Old alert should be resolved
    $activeAlerts = ServerAlert::where('server_id', $server->id)->active()->get();
    expect($activeAlerts)->toHaveCount(0);

    $resolvedAlerts = ServerAlert::where('server_id', $server->id)->resolved()->get();
    expect($resolvedAlerts)->toHaveCount(1);
});

test('threshold checker respects cooldown — no duplicate alert within 15 minutes', function () {
    $server = Server::factory()->online()->create();

    // Create a recent CPU alert (2 minutes ago)
    ServerAlert::create([
        'server_id' => $server->id,
        'metric_type' => 'cpu',
        'value' => 92.0,
        'threshold' => 90.0,
        'message' => 'CPU alert',
        'created_at' => now()->subMinutes(2),
    ]);

    $checker = new ThresholdChecker;
    $checker->check($server, [
        'cpu_percent' => 96.0,
        'mem_total' => 2048,
        'mem_used' => 800,
        'disk_total' => 4096,
        'disk_used' => 1000,
        'load1' => 1.0,
    ]);

    // Should NOT create a new alert (cooldown active)
    $alerts = ServerAlert::where('server_id', $server->id)->active()->get();
    expect($alerts)->toHaveCount(1);
});

test('threshold checker allows new alert after cooldown expires', function () {
    $server = Server::factory()->online()->create();

    // Create an old CPU alert (20 minutes ago)
    ServerAlert::create([
        'server_id' => $server->id,
        'metric_type' => 'cpu',
        'value' => 92.0,
        'threshold' => 90.0,
        'message' => 'CPU alert',
        'created_at' => now()->subMinutes(20),
    ]);

    $checker = new ThresholdChecker;
    $checker->check($server, [
        'cpu_percent' => 96.0,
        'mem_total' => 2048,
        'mem_used' => 800,
        'disk_total' => 4096,
        'disk_used' => 1000,
        'load1' => 1.0,
    ]);

    // Old alert resolved + new alert created
    $activeAlerts = ServerAlert::where('server_id', $server->id)->active()->get();
    expect($activeAlerts)->toHaveCount(1);
    expect($activeAlerts->first()->value)->toBe(96.0);
});

// ──────────────────────────────────────────────────────
// Multiple metric types: CPU, RAM, Disk
// ──────────────────────────────────────────────────────

test('threshold checker alerts on high memory usage', function () {
    $server = Server::factory()->online()->create();

    $checker = new ThresholdChecker;
    $checker->check($server, [
        'cpu_percent' => 20.0,
        'mem_total' => 1000,
        'mem_used' => 950, // 95%
        'disk_total' => 4096,
        'disk_used' => 1000,
        'load1' => 0.5,
    ]);

    $alerts = ServerAlert::where('server_id', $server->id)->active()->get();
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->metric_type)->toBe('memory');
});

test('threshold checker alerts on high disk usage', function () {
    $server = Server::factory()->online()->create();

    $checker = new ThresholdChecker;
    $checker->check($server, [
        'cpu_percent' => 20.0,
        'mem_total' => 2048,
        'mem_used' => 500,
        'disk_total' => 1000,
        'disk_used' => 950, // 95%
        'load1' => 0.5,
    ]);

    $alerts = ServerAlert::where('server_id', $server->id)->active()->get();
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->metric_type)->toBe('disk');
});

test('threshold checker can alert on multiple metrics simultaneously', function () {
    $server = Server::factory()->online()->create();

    $checker = new ThresholdChecker;
    $checker->check($server, [
        'cpu_percent' => 95.0,
        'mem_total' => 1000,
        'mem_used' => 950,
        'disk_total' => 1000,
        'disk_used' => 950,
        'load1' => 5.0,
    ]);

    $alerts = ServerAlert::where('server_id', $server->id)->active()->get();
    expect($alerts)->toHaveCount(3);
    $types = $alerts->pluck('metric_type')->toArray();
    expect($types)->toContain('cpu');
    expect($types)->toContain('memory');
    expect($types)->toContain('disk');
});

// ──────────────────────────────────────────────────────
// ServerAlertController: API for dashboard
// ──────────────────────────────────────────────────────

test('alerts endpoint returns active alerts', function () {
    $server = Server::factory()->online()->create();

    ServerAlert::create([
        'server_id' => $server->id,
        'metric_type' => 'cpu',
        'value' => 95.0,
        'threshold' => 90.0,
        'message' => 'CPU alert',
    ]);

    ServerAlert::create([
        'server_id' => $server->id,
        'metric_type' => 'cpu',
        'value' => 93.0,
        'threshold' => 90.0,
        'message' => 'Old CPU alert',
        'resolved_at' => now()->subHour(),
    ]);

    $controller = new \App\Http\Controllers\ServerAlertController;
    $response = $controller->index();

    $reflection = new \ReflectionClass($response);
    $propsProp = $reflection->getProperty('props');
    $propsProp->setAccessible(true);
    $rawProps = $propsProp->getValue($response);
    $resolved = [];
    foreach ($rawProps as $key => $value) {
        $resolved[$key] = $value instanceof \Closure ? $value() : $value;
    }

    expect($resolved['activeAlerts'])->toHaveCount(1);
    expect($resolved['activeAlerts'][0]['metric_type'])->toBe('cpu');
    expect($resolved['activeCount'])->toBe(1);
    expect($resolved['recentResolved'])->toHaveCount(1);
});
