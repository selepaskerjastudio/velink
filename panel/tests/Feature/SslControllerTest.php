<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockSslGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to login', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $this->post(route('applications.ssl', $application))->assertRedirect('/login');
});

test('enable ssl dispatches a certbot shell job', function () {
    mockSslGatewayPublish();

    $user = User::factory()->create(['email' => 'ops@example.com']);
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'name' => 'My App',
    ]);

    $response = $this->post(route('applications.ssl', $application));

    $response->assertRedirect(route('applications.show', $application));

    $job = AgentJob::where('application_id', $application->id)->latest('id')->first();
    expect($job)->not->toBeNull();
    expect($job->type)->toBe('shell');
    expect($job->payload['command'])->toContain('certbot');
    expect($job->payload['command'])->toContain('myapp.example.com');
    expect($job->label)->toBe('Enable SSL for myapp.example.com');

    $log = AuditLog::where('action', 'application.ssl_enabled')->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->server_id)->toBe($server->id);
    expect($log->description)->toContain('myapp.example.com');
});

test('enable ssl requires a domain', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => null,
        'status' => 'active',
    ]);

    $response = $this->post(route('applications.ssl', $application));

    $response->assertSessionHasErrors('domain');
    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});

test('enable ssl requires provisioned status', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'pending',
    ]);

    $response = $this->post(route('applications.ssl', $application));

    $response->assertSessionHasErrors('domain');
    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});
