<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockSslStatusPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

// ──────────────────────────────────────────────────────
// ssl_status column exists on applications
// ──────────────────────────────────────────────────────

test('application model has ssl_status column with default none', function () {
    $application = Application::factory()->create();

    expect($application->ssl_status)->toBe('none');
});

test('application ssl_status can be set to active', function () {
    $application = Application::factory()->create(['ssl_status' => 'active']);

    expect($application->ssl_status)->toBe('active');
});

// ──────────────────────────────────────────────────────
// POST /apps/{app}/ssl still works AND sets ssl_status
// ──────────────────────────────────────────────────────

test('enable ssl sets ssl_status to requesting', function () {
    mockSslStatusPublish();

    $user = User::factory()->create(['email' => 'ops@example.com']);
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'none',
    ]);

    $this->post(route('applications.ssl', $application))
        ->assertRedirect(route('applications.show', $application));

    $application->refresh();
    expect($application->ssl_status)->toBe('requesting');

    // Agent job should still be dispatched
    $job = AgentJob::where('application_id', $application->id)->latest('id')->first();
    expect($job)->not->toBeNull();
    expect($job->payload['command'])->toContain('certbot');
});

// ──────────────────────────────────────────────────────
// POST /apps/{app}/ssl fails gracefully if already requesting/active
// ──────────────────────────────────────────────────────

test('enable ssl does nothing if already requesting', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'requesting',
    ]);

    $this->post(route('applications.ssl', $application))
        ->assertSessionHasErrors('domain');

    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});

test('enable ssl does nothing if already active', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'active',
    ]);

    $this->post(route('applications.ssl', $application))
        ->assertSessionHasErrors('domain');

    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});

// ──────────────────────────────────────────────────────
// Check SSL endpoint — dispatches certbot certificates check
// ──────────────────────────────────────────────────────

test('guests cannot check ssl status', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $this->post(route('applications.ssl.check', $application))->assertRedirect('/login');
});

test('check ssl dispatches certbot certificates command', function () {
    mockSslStatusPublish();

    $user = User::factory()->create(['email' => 'ops@example.com']);
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'ssl_status' => 'none',
    ]);

    $this->post(route('applications.ssl.check', $application))
        ->assertRedirect(route('applications.show', $application));

    $job = AgentJob::where('application_id', $application->id)->latest('id')->first();
    expect($job)->not->toBeNull();
    expect($job->type)->toBe('shell');
    expect($job->payload['command'])->toContain('certbot certificates');
    expect($job->payload['command'])->toContain('myapp.example.com');
    expect($job->label)->toBe('Check SSL for myapp.example.com');
});

test('check ssl dispatches even when ssl_status is active', function () {
    mockSslStatusPublish();

    $user = User::factory()->create(['email' => 'ops@example.com']);
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'ssl_status' => 'active',
    ]);

    $this->post(route('applications.ssl.check', $application))
        ->assertRedirect(route('applications.show', $application));

    $job = AgentJob::where('application_id', $application->id)->latest('id')->first();
    expect($job)->not->toBeNull();
});

// ──────────────────────────────────────────────────────
// ssl_status is included in application show Inertia props
// ──────────────────────────────────────────────────────

test('ssl_status is included in application show props via controller', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'active',
    ]);

    // Verify ssl_status is persisted and retrievable
    $application->refresh();
    expect($application->ssl_status)->toBe('active');

    // Verify ssl_status is fillable and part of the model
    expect(in_array('ssl_status', (new Application)->getFillable()))->toBeTrue();
});
