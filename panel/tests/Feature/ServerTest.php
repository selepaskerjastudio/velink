<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get('/servers')->assertRedirect('/login');
});

test('authenticated users can view the servers list', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/servers')->assertOk();
});

test('authenticated users can view the add server page', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/servers/create')->assertOk();
});

test('a server can be created and shows the agent token once', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->post('/servers', [
        'name' => 'web-01',
        'hostname' => 'web-01.internal',
        'public_ip' => '203.0.113.10',
        'private_ip' => '10.0.0.10',
        'os' => 'Ubuntu 24.04',
    ]);

    $server = Server::query()->where('name', 'web-01')->firstOrFail();

    $response->assertRedirect(route('servers.show', $server));

    $this->assertNotNull($server->agent_token);

    $follow = $this->get(route('servers.show', $server));
    $follow->assertOk();
    $follow->assertInertia(fn ($page) => $page
        ->where('flash.plainAgentToken', fn ($token) => filled($token))
        ->where('flash.installCommand', fn ($command) => str_contains($command, $server->uuid))
    );
});

test('the connect page redirects to the server dashboard when already online', function () {
    $this->actingAs(User::factory()->create());

    $server = Server::factory()->online()->create();

    $this->get(route('servers.connect', $server))
        ->assertRedirect(route('servers.show', $server));
});

test('the connect page renders for a server that is not yet online', function () {
    $this->actingAs(User::factory()->create());

    $server = Server::factory()->create(['status' => 'pending']);

    $this->get(route('servers.connect', $server))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('servers/connect'));
});
