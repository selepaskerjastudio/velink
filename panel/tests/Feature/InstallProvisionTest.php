<?php

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('a valid token clears stale services and jobs so the server re-provisions', function () {
    $server = Server::factory()->create(['agent_token' => 'plain-agent-token']);
    Service::create(['server_id' => $server->id, 'type' => 'systemd', 'name' => 'nginx', 'status' => 'active']);
    AgentJob::factory()->for($server)->create(['label' => 'Install nginx']);

    $response = $this->postJson('/install/provision', [
        'server_id' => $server->uuid,
        'token' => 'plain-agent-token',
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
    expect($server->services()->where('type', 'systemd')->count())->toBe(0)
        ->and($server->agentJobs()->count())->toBe(0);
});

test('a wrong token is rejected and leaves records untouched', function () {
    $server = Server::factory()->create(['agent_token' => 'plain-agent-token']);
    Service::create(['server_id' => $server->id, 'type' => 'systemd', 'name' => 'nginx', 'status' => 'active']);

    $this->postJson('/install/provision', [
        'server_id' => $server->uuid,
        'token' => 'wrong-token',
    ])->assertStatus(401)->assertJson(['ok' => false]);

    expect($server->services()->where('type', 'systemd')->count())->toBe(1);
});

test('an unknown server uuid is rejected', function () {
    $this->postJson('/install/provision', [
        'server_id' => (string) Str::uuid(),
        'token' => 'whatever',
    ])->assertStatus(401);
});

test('a non-uuid server_id is a validation error', function () {
    $this->postJson('/install/provision', [
        'server_id' => '12345',
        'token' => 'whatever',
    ])->assertStatus(422);
});
