<?php

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();

    $this->post(route('servers.provision', $server), ['components' => ['nginx']])
        ->assertRedirect('/login');
});

test('authenticated users can provision selected components', function () {
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('servers.provision', $server), [
        'components' => ['nginx', 'php'],
        'php_versions' => ['8.3'],
    ]);

    $response->assertRedirect(route('servers.show', $server));

    // base + nginx + php (PPA + one install) = 4 jobs.
    expect($server->agentJobs()->count())->toBe(4);
    expect($server->agentJobs()->pluck('label'))->toContain('Install nginx', 'Install PHP 8.3');
    expect($server->agentJobs()->pluck('type')->unique()->all())->toBe(['shell']);
});

test('provisioning auto-seeds well-known services', function () {
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $this->post(route('servers.provision', $server), [
        'components' => ['nginx', 'redis', 'php'],
        'php_versions' => ['8.3', '8.4'],
    ])->assertRedirect();

    $services = $server->services()->where('type', 'systemd')->pluck('name')->sort()->values();

    expect($services->all())->toEqual(['nginx', 'php8.3-fpm', 'php8.4-fpm', 'redis-server']);
});

test('provisioning auto-seeding is idempotent when run twice', function () {
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $payload = ['components' => ['nginx', 'supervisor'], 'php_versions' => []];
    $this->post(route('servers.provision', $server), $payload)->assertRedirect();
    $this->post(route('servers.provision', $server), $payload)->assertRedirect();

    expect($server->services()->where('type', 'systemd')->where('name', 'nginx')->count())->toBe(1);
    expect($server->services()->where('type', 'systemd')->count())->toBe(2);
});

test('php component requires at least one php version', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('servers.provision', $server), [
        'components' => ['php'],
        'php_versions' => [],
    ]);

    $response->assertSessionHasErrors('php_versions');
    expect($server->agentJobs()->count())->toBe(0);
});

test('unknown components are rejected', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('servers.provision', $server), [
        'components' => ['frobnicate'],
    ]);

    $response->assertSessionHasErrors('components.0');
    expect(AgentJob::count())->toBe(0);
});
