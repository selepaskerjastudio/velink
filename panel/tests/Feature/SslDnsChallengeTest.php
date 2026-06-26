<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\CloudflareToken;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockSslPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('enableSsl with dns challenge writes cloudflare ini + certbot dns command', function () {
    mockSslPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
    ]);
    CloudflareToken::create([
        'user_id' => $user->id, 'email' => 'a@b.com', 'api_token' => 'cf-secret', 'verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('applications.ssl', $application), ['challenge' => 'dns'])
        ->assertRedirect(route('applications.show', $application));

    // Three jobs dispatched: write_file (creds), shell (certbot dns), shell (cleanup).
    $jobs = AgentJob::where('application_id', $application->id)->orderBy('id')->get();
    expect($jobs)->toHaveCount(3);

    expect($jobs[0]->type)->toBe('write_file');
    expect($jobs[0]->payload['path'])->toBe('/root/.cloudflare.ini');
    expect($jobs[0]->payload['mode'])->toBe('0600');

    expect($jobs[1]->type)->toBe('shell');
    expect($jobs[1]->payload['command'])->toContain('--dns-cloudflare');
    expect($jobs[1]->payload['command'])->toContain('--dns-cloudflare-credentials');

    expect($jobs[2]->type)->toBe('shell');
    expect($jobs[2]->payload['command'])->toContain('rm -f /root/.cloudflare.ini');

    // ssl_challenge stored.
    expect($application->refresh()->ssl_challenge)->toBe('dns');
});

test('enableSsl falls back to http when no cloudflare token', function () {
    mockSslPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
    ]);
    // No CloudflareToken created.

    $this->actingAs($user)
        ->post(route('applications.ssl', $application), ['challenge' => 'dns'])
        ->assertRedirect(route('applications.show', $application));

    // Falls back to single HTTP-01 certbot job (no creds write/cleanup).
    $jobs = AgentJob::where('application_id', $application->id)->get();
    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->payload['command'])->toContain('certbot --nginx');
    expect($application->refresh()->ssl_challenge)->toBe('http');
});

test('enableSsl with http challenge uses the existing certbot nginx flow', function () {
    mockSslPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->post(route('applications.ssl', $application), ['challenge' => 'http'])
        ->assertRedirect(route('applications.show', $application));

    $jobs = AgentJob::where('application_id', $application->id)->get();
    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->payload['command'])->toContain('--nginx');
    expect($jobs[0]->payload['command'])->toContain('--redirect');
});
