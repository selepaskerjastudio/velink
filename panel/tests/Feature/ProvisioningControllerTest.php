<?php

use App\Models\AgentJob;
use App\Models\Server;
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
