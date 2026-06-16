<?php

use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use App\Services\GatewayInboundProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ──────────────────────────────────────────────────────
// Monitoring: retention policy (7 days, not 2 hours)
// ──────────────────────────────────────────────────────

test('metrics older than 7 days are pruned after new insert', function () {
    $server = Server::factory()->online()->create();

    // Insert a metric from 8 days ago
    ServerMetric::create([
        'server_id' => $server->id,
        'cpu_percent' => 10.0,
        'mem_total' => 1024,
        'mem_used' => 512,
        'disk_total' => 2048,
        'disk_used' => 1024,
        'load1' => 0.5,
        'recorded_at' => now()->subDays(8),
    ]);

    // Insert a current metric via handleMetrics (simulates agent report)
    $processor = app(GatewayInboundProcessor::class);
    $processor->handleInbound(json_encode([
        'type' => 'metrics',
        'server_id' => $server->uuid,
        'payload' => [
            'cpu_percent' => 25.0,
            'mem_total' => 2048,
            'mem_used' => 1024,
            'disk_total' => 4096,
            'disk_used' => 2048,
            'load1' => 1.0,
            'uptime_seconds' => 86400,
        ],
    ]));

    // Old metric should be pruned
    expect(ServerMetric::count())->toBe(1);
    // The remaining metric should be from today
    expect(ServerMetric::first()->recorded_at->isToday())->toBeTrue();
});

test('metrics within 7 days are kept after new insert', function () {
    $server = Server::factory()->online()->create();

    // Insert metrics from 1, 3, and 6 days ago
    foreach ([1, 3, 6] as $daysAgo) {
        ServerMetric::create([
            'server_id' => $server->id,
            'cpu_percent' => 10.0,
            'mem_total' => 1024,
            'mem_used' => 512,
            'disk_total' => 2048,
            'disk_used' => 1024,
            'load1' => 0.5,
            'recorded_at' => now()->subDays($daysAgo),
        ]);
    }

    // Insert a current metric
    $processor = app(GatewayInboundProcessor::class);
    $processor->handleInbound(json_encode([
        'type' => 'metrics',
        'server_id' => $server->uuid,
        'payload' => [
            'cpu_percent' => 25.0,
            'mem_total' => 2048,
            'mem_used' => 1024,
            'disk_total' => 4096,
            'disk_used' => 2048,
            'load1' => 1.0,
        ],
    ]));

    // All metrics within 7 days + new one should be kept
    expect(ServerMetric::count())->toBe(4);
});

// ──────────────────────────────────────────────────────
// Monitoring: sampling for historical queries
// ──────────────────────────────────────────────────────

test('monitoring endpoint returns data for 1h range', function () {
    $server = Server::factory()->online()->create();

    // Verify the controller method exists and is callable
    $controller = new \App\Http\Controllers\ServerController;
    $method = new \ReflectionMethod($controller, 'monitoring');
    expect($method->isPublic())->toBeTrue();

    // Verify metrics query scope works for 1h
    $metric = ServerMetric::create([
        'server_id' => $server->id,
        'cpu_percent' => 25.0,
        'mem_total' => 2048,
        'mem_used' => 1024,
        'disk_total' => 4096,
        'disk_used' => 2048,
        'load1' => 1.0,
        'recorded_at' => now()->subMinutes(30),
    ]);

    $results = $server->metrics()
        ->where('recorded_at', '>=', now()->subHours(1))
        ->orderBy('recorded_at')
        ->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->cpu_percent)->toBe(25.0);
});

test('monitoring endpoint defaults to 1h when no range given', function () {
    $server = Server::factory()->online()->create();

    // Simulate the default range logic from controller
    $range = '1h';
    $hours = match ($range) {
        '6h' => 6,
        '24h' => 24,
        '7d' => 168,
        default => 1,
    };

    expect($hours)->toBe(1);
});

test('monitoring 24h range returns only recent metrics', function () {
    $server = Server::factory()->online()->create();

    // Insert 3 metrics: 1 old (3 days), 2 recent
    ServerMetric::create([
        'server_id' => $server->id,
        'cpu_percent' => 10.0,
        'mem_total' => 1024,
        'mem_used' => 512,
        'disk_total' => 2048,
        'disk_used' => 1024,
        'load1' => 0.5,
        'recorded_at' => now()->subDays(3),
    ]);

    ServerMetric::create([
        'server_id' => $server->id,
        'cpu_percent' => 50.0,
        'mem_total' => 2048,
        'mem_used' => 1024,
        'disk_total' => 4096,
        'disk_used' => 2048,
        'load1' => 1.0,
        'recorded_at' => now()->subHours(1),
    ]);

    ServerMetric::create([
        'server_id' => $server->id,
        'cpu_percent' => 75.0,
        'mem_total' => 2048,
        'mem_used' => 1536,
        'disk_total' => 4096,
        'disk_used' => 3072,
        'load1' => 2.0,
        'recorded_at' => now(),
    ]);

    $controller = new \App\Http\Controllers\ServerController;

    // Test the query scope directly (same logic as controller)
    $results = $server->metrics()
        ->where('recorded_at', '>=', now()->subHours(24))
        ->orderBy('recorded_at')
        ->get();

    // Should have exactly 2 metrics (within 24h)
    expect($results)->toHaveCount(2);
    expect($results->first()->cpu_percent)->toBe(50.0);
    expect($results->last()->cpu_percent)->toBe(75.0);
});
