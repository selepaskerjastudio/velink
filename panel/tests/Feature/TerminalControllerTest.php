<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.gateway.secret' => 'test-gateway-secret']);
});

test('guests cannot access the terminal page', function () {
    $server = Server::factory()->create();

    $this->get(route('servers.terminal', $server))->assertRedirect('/login');
});

test('the terminal page generates a one-time session token', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    // The token is stored in cache — verify it exists after loading the page.
    $this->actingAs($user)
        ->get(route('servers.terminal', $server))
        ->assertInertia(fn ($page) => $page
            ->component('servers/terminal')
            ->has('terminalToken')
            ->has('gatewayUrl')
            ->has('systemUsers')
            ->where('terminalToken', fn ($token) => filled($token) && Cache::get("terminal:session:{$token}") !== null)
        );
});

test('terminal auth validates a valid session token', function () {
    $server = Server::factory()->online()->create();
    $token = fake()->uuid();

    Cache::put("terminal:session:{$token}", [
        'server_uuid' => $server->uuid,
        'server_id' => $server->id,
        'user_id' => 1,
    ], 60);

    $response = $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $server->uuid,
            'session_token' => $token,
        ]);

    $response->assertOk()
        ->assertJson(['valid' => true]);

    // Token is single-use — deleted after auth.
    expect(Cache::get("terminal:session:{$token}"))->toBeNull();
});

test('terminal auth rejects an expired or invalid token', function () {
    $server = Server::factory()->online()->create();

    $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $server->uuid,
            'session_token' => 'nonexistent-token',
        ])
        ->assertStatus(401);
});

test('terminal auth rejects mismatched server UUID', function () {
    $server = Server::factory()->online()->create();
    $other = Server::factory()->online()->create();
    $token = fake()->uuid();

    Cache::put("terminal:session:{$token}", [
        'server_uuid' => $server->uuid,
        'server_id' => $server->id,
        'user_id' => 1,
    ], 60);

    $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $other->uuid,
            'session_token' => $token,
        ])
        ->assertStatus(403);
});

test('terminal page includes available system users', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $server->systemUsers()->create(['username' => 'deployer', 'shell' => '/bin/bash']);

    $this->actingAs($user)
        ->get(route('servers.terminal', $server))
        ->assertInertia(fn ($page) => $page
            ->where('systemUsers', fn ($users) => collect($users)->contains('root') && collect($users)->contains('deployer'))
        );
});
