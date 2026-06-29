<?php

use App\Models\Application;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'velink.edge_proxy.driver' => 'caddy',
        'velink.edge_proxy.ask_secret' => 'ask-secret',
    ]);
});

function edgeAuthApp(bool $edge, string $domain): Application
{
    $server = Server::factory()->create(['uses_edge_proxy' => $edge]);

    return Application::factory()->create(['server_id' => $server->id, 'domain' => $domain]);
}

test('authorizes a domain on an edge-backed server with the right key', function () {
    edgeAuthApp(true, 'app.example.com');

    $this->get('/internal/caddy/authorize?key=ask-secret&domain=app.example.com')
        ->assertOk();
});

test('rejects a wrong key', function () {
    edgeAuthApp(true, 'app.example.com');

    $this->get('/internal/caddy/authorize?key=nope&domain=app.example.com')
        ->assertForbidden();
});

test('rejects an unknown domain', function () {
    $this->get('/internal/caddy/authorize?key=ask-secret&domain=stranger.com')
        ->assertForbidden();
});

test('rejects a domain on a native (non-edge) server', function () {
    edgeAuthApp(false, 'native.example.com');

    $this->get('/internal/caddy/authorize?key=ask-secret&domain=native.example.com')
        ->assertForbidden();
});

test('returns 404 when the edge proxy is disabled', function () {
    config(['velink.edge_proxy.driver' => 'none']);
    edgeAuthApp(true, 'app.example.com');

    $this->get('/internal/caddy/authorize?key=ask-secret&domain=app.example.com')
        ->assertNotFound();
});
