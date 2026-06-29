<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use App\Services\Edge\EdgeProxy;
use App\Services\Edge\EdgeProxySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/** @return array{0: EdgeProxySync, 1: MockInterface} */
function syncWithSpy(): array
{
    $proxy = Mockery::spy(EdgeProxy::class);

    return [new EdgeProxySync($proxy), $proxy];
}

test('onProvisioned pushes a route via the internal IP for an edge-backed server', function () {
    [$sync, $proxy] = syncWithSpy();
    $server = Server::factory()->create([
        'uses_edge_proxy' => true,
        'private_ip' => '10.0.0.30',
        'public_ip' => '1.2.3.4',
    ]);
    $app = Application::factory()->create(['server_id' => $server->id, 'domain' => 'app.example.com']);

    $sync->onProvisioned($app);

    $proxy->shouldHaveReceived('addRoute')
        ->with('app.example.com', '10.0.0.30:80', $app->uuid)
        ->once();
});

test('onProvisioned does nothing for a native server', function () {
    [$sync, $proxy] = syncWithSpy();
    $server = Server::factory()->create(['uses_edge_proxy' => false]);
    $app = Application::factory()->create(['server_id' => $server->id, 'domain' => 'app.example.com']);

    $sync->onProvisioned($app);

    $proxy->shouldNotHaveReceived('addRoute');
});

test('upstream falls back to the public IP when there is no private IP', function () {
    [$sync, $proxy] = syncWithSpy();
    $server = Server::factory()->create([
        'uses_edge_proxy' => true,
        'private_ip' => null,
        'public_ip' => '5.6.7.8',
    ]);
    $app = Application::factory()->create(['server_id' => $server->id, 'domain' => 'app.example.com']);

    $sync->onProvisioned($app);

    $proxy->shouldHaveReceived('addRoute')
        ->with('app.example.com', '5.6.7.8:80', $app->uuid)
        ->once();
});

test('onDeleted removes the route for an edge-backed server', function () {
    [$sync, $proxy] = syncWithSpy();
    $server = Server::factory()->create(['uses_edge_proxy' => true]);
    $app = Application::factory()->create(['server_id' => $server->id, 'domain' => 'app.example.com']);

    $sync->onDeleted($app);

    $proxy->shouldHaveReceived('removeRoute')->with($app->uuid)->once();
});

test('onDomainChanged removes the route when the domain is cleared', function () {
    [$sync, $proxy] = syncWithSpy();
    $server = Server::factory()->create(['uses_edge_proxy' => true]);
    $app = Application::factory()->create(['server_id' => $server->id, 'domain' => null]);

    $sync->onDomainChanged($app);

    $proxy->shouldHaveReceived('removeRoute')->with($app->uuid)->once();
});

test('enableSsl is blocked on an edge-backed server', function () {
    $this->actingAs(User::factory()->create());

    $server = Server::factory()->online()->create(['uses_edge_proxy' => true]);
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'app.example.com',
        'status' => 'active',
    ]);

    $this->post(route('applications.ssl', $app))
        ->assertSessionHasErrors('domain');
});
