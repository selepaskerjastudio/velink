<?php

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

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

    // The whole catalog is seeded; requested components are `waiting`, the rest
    // `not_installed` so they can be installed on demand later.
    $services = $server->services()->where('type', 'systemd')->get()->keyBy('name');

    expect($services['nginx']->status)->toBe('waiting')
        ->and($services['redis-server']->status)->toBe('waiting')
        ->and($services['php8.3-fpm']->status)->toBe('waiting')
        ->and($services['php8.4-fpm']->status)->toBe('waiting')
        ->and($services['mariadb']->status)->toBe('not_installed')
        ->and($services['php8.1-fpm']->status)->toBe('not_installed');
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

    // No duplicates, and the full systemd catalog (6 well-known + 5 PHP) is seeded once.
    expect($server->services()->where('type', 'systemd')->where('name', 'nginx')->count())->toBe(1)
        ->and($server->services()->where('type', 'systemd')->count())->toBe(11)
        ->and($server->services()->where('name', 'nginx')->first()->status)->toBe('waiting')
        ->and($server->services()->where('name', 'redis-server')->first()->status)->toBe('not_installed');
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
