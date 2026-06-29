<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockDomainPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

function makeDomainApp(): array
{
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'old.example.com',
        'status' => 'active',
    ]);

    return [$user, $app, $server];
}

test('guests cannot update domain', function () {
    [, $app] = makeDomainApp();

    $this->patch(route('applications.domain', $app), ['domain' => 'new.com'])
        ->assertRedirect('/login');
});

test('domain can be updated', function () {
    mockDomainPublish();

    [$user, $app] = makeDomainApp();

    $this->actingAs($user)
        ->patch(route('applications.domain', $app), ['domain' => 'new.example.com'])
        ->assertRedirect(route('applications.show', $app));

    expect($app->refresh()->domain)->toBe('new.example.com');
});

test('update_domain rewrites nginx vhost and removes old one', function () {
    mockDomainPublish();

    [$user, $app] = makeDomainApp();

    $this->actingAs($user)
        ->patch(route('applications.domain', $app), ['domain' => 'new.example.com']);

    $jobs = \App\Models\AgentJob::where('application_id', $app->id)
        ->where('type', 'render_config')
        ->get();

    // New vhost rendered for new domain
    expect($jobs)->not->toBeEmpty();
    $lastRender = $jobs->last();
    expect($lastRender->payload['path'])->toContain('new.example.com.conf');

    // Old vhost removed (shell job with rm -f)
    $shellJobs = \App\Models\AgentJob::where('application_id', $app->id)
        ->where('type', 'shell')
        ->get();
    $hasRemoval = $shellJobs->contains(fn ($j) => str_contains($j->payload['command'] ?? '', 'old.example.com'));
    expect($hasRemoval)->toBeTrue();
});

test('update_domain recreates sites-enabled symlink', function () {
    mockDomainPublish();

    [$user, $app] = makeDomainApp();

    $this->actingAs($user)
        ->patch(route('applications.domain', $app), ['domain' => 'new.example.com']);

    $shellJobs = \App\Models\AgentJob::where('application_id', $app->id)
        ->where('type', 'shell')
        ->get();
    $hasSymlink = $shellJobs->contains(fn ($j) => str_contains($j->payload['command'] ?? '', 'ln -sf'));
    $hasReload = $shellJobs->contains(fn ($j) => str_contains($j->payload['command'] ?? '', 'nginx -t'));
    expect($hasSymlink)->toBeTrue();
    expect($hasReload)->toBeTrue();
});

test('update_domain invalidates existing SSL', function () {
    mockDomainPublish();

    [$user, $app] = makeDomainApp();
    $app->forceFill(['ssl_enabled_at' => now(), 'ssl_challenge' => 'http'])->save();

    $this->actingAs($user)
        ->patch(route('applications.domain', $app), ['domain' => 'new.example.com']);

    expect($app->refresh()->ssl_enabled_at)->toBeNull()
        ->and($app->refresh()->ssl_challenge)->toBeNull();
});

test('update_domain rejects invalid domain format', function () {
    [$user, $app] = makeDomainApp();

    $this->actingAs($user)
        ->from(route('applications.show', $app))
        ->patch(route('applications.domain', $app), ['domain' => 'not a domain'])
        ->assertSessionHasErrors('domain');
});

test('update_domain rejects duplicate domain', function () {
    [$user, $app] = makeDomainApp();
    Application::factory()->create([
        'server_id' => $app->server_id,
        'domain' => 'taken.com',
    ]);

    $this->actingAs($user)
        ->from(route('applications.show', $app))
        ->patch(route('applications.domain', $app), ['domain' => 'taken.com'])
        ->assertSessionHasErrors('domain');
});

test('update_domain allows setting domain to empty for static sites', function () {
    mockDomainPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'old.example.com',
        'app_type' => 'static',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->patch(route('applications.domain', $app), ['domain' => null])
        ->assertRedirect(route('applications.show', $app));

    expect($app->refresh()->domain)->toBeNull();
});
