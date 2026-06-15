<?php

use App\Models\Server;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config(['services.gateway.secret' => 'test-gateway-secret']);
});

function makeServer(string $token = 'plain-agent-token'): Server
{
    return Server::create([
        'name' => 'web-01',
        'status' => 'pending',
        'agent_token' => $token, // hashed by the model cast
    ]);
}

test('valid token and secret returns the server', function () {
    $server = makeServer('plain-agent-token');

    $response = $this->withHeader('X-Gateway-Secret', 'test-gateway-secret')
        ->postJson('/internal/agent/verify', [
            'server_id' => $server->id,
            'token' => 'plain-agent-token',
        ]);

    $response->assertOk();
    $response->assertJson([
        'valid' => true,
        'server' => ['id' => $server->id, 'name' => 'web-01'],
    ]);
});

test('wrong token is rejected', function () {
    $server = makeServer('plain-agent-token');

    $this->withHeader('X-Gateway-Secret', 'test-gateway-secret')
        ->postJson('/internal/agent/verify', [
            'server_id' => $server->id,
            'token' => 'wrong-token',
        ])
        ->assertStatus(401)
        ->assertJson(['valid' => false]);
});

test('missing gateway secret is rejected', function () {
    $server = makeServer();

    $this->postJson('/internal/agent/verify', [
        'server_id' => $server->id,
        'token' => 'plain-agent-token',
    ])->assertStatus(401);
});

test('wrong gateway secret is rejected', function () {
    $server = makeServer();

    $this->withHeader('X-Gateway-Secret', 'nope')
        ->postJson('/internal/agent/verify', [
            'server_id' => $server->id,
            'token' => 'plain-agent-token',
        ])->assertStatus(401);
});

test('unknown server returns invalid', function () {
    $this->withHeader('X-Gateway-Secret', 'test-gateway-secret')
        ->postJson('/internal/agent/verify', [
            'server_id' => 999999,
            'token' => 'whatever',
        ])->assertStatus(401)->assertJson(['valid' => false]);
});
