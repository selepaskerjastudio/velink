<?php

use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockServiceGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();
    $service = Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'unknown',
    ]);

    $this->get(route('services.index', $server))->assertRedirect('/login');
    $this->post(route('services.store', $server), ['name' => 'nginx'])->assertRedirect('/login');
    $this->post(route('services.control', $service), ['action' => 'start'])->assertRedirect('/login');
    $this->delete(route('services.destroy', $service))->assertRedirect('/login');
});

test('a service can be registered', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('services.store', $server), [
        'name' => 'nginx',
        'label' => 'Web server',
    ]);

    $response->assertRedirect(route('services.index', $server));

    $service = Service::where('server_id', $server->id)->where('name', 'nginx')->first();
    expect($service)->not->toBeNull();
    expect($service->type)->toBe('systemd');
    expect($service->status)->toBe('unknown');
    expect($service->config)->toBe(['label' => 'Web server']);
});

test('registering a service rejects unit names with shell injection attempts', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('services.store', $server), [
        'name' => 'nginx; rm -rf /',
    ]);
    $response->assertSessionHasErrors('name');

    $response = $this->post(route('services.store', $server), [
        'name' => 'nginx && evil',
    ]);
    $response->assertSessionHasErrors('name');

    expect(Service::where('server_id', $server->id)->count())->toBe(0);
});

test('registering a service accepts valid systemd unit names', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('services.store', $server), [
        'name' => 'php8.3-fpm.service',
    ]);

    $response->assertRedirect(route('services.index', $server));
    expect(Service::where('server_id', $server->id)->where('name', 'php8.3-fpm.service')->exists())->toBeTrue();
});

test('service names must be unique per server and type', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'unknown',
    ]);

    $response = $this->post(route('services.store', $server), [
        'name' => 'nginx',
    ]);

    $response->assertSessionHasErrors('name');
    expect(Service::where('server_id', $server->id)->where('name', 'nginx')->count())->toBe(1);
});

test('the same service name can be registered on different servers', function () {
    $this->actingAs(User::factory()->create());
    $serverA = Server::factory()->online()->create();
    $serverB = Server::factory()->online()->create();

    Service::create([
        'server_id' => $serverA->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'unknown',
    ]);

    $response = $this->post(route('services.store', $serverB), [
        'name' => 'nginx',
    ]);

    $response->assertRedirect(route('services.index', $serverB));
    expect(Service::where('server_id', $serverB->id)->where('name', 'nginx')->exists())->toBeTrue();
});

test('control dispatches a shell job and optimistically updates status for start/stop/restart/reload', function ($action, $expectedStatus) {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $service = Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'unknown',
    ]);

    $response = $this->post(route('services.control', $service), [
        'action' => $action,
    ]);

    $response->assertRedirect(route('services.index', $server));

    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['action'])->toBe('shell');
    expect($published[0]['payload']['params']['command'])->toContain("systemctl {$action} 'nginx'");

    $job = $server->agentJobs()->where('type', 'shell')->first();
    expect($job)->not->toBeNull();
    expect($job->uuid)->toBe($published[0]['job_id']);

    expect($service->refresh()->status)->toBe($expectedStatus);
})->with([
    ['start', 'active'],
    ['restart', 'active'],
    ['reload', 'active'],
    ['stop', 'inactive'],
]);

test('control enable/disable updates config.enabled without changing status', function ($action, $expectedEnabled) {
    mockServiceGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $service = Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'active',
    ]);

    $response = $this->post(route('services.control', $service), [
        'action' => $action,
    ]);

    $response->assertRedirect(route('services.index', $server));

    $service->refresh();
    expect($service->status)->toBe('active');
    expect($service->config)->toBe(['enabled' => $expectedEnabled]);

    $job = $server->agentJobs()->where('type', 'shell')->first();
    expect($job->payload['command'])->toContain("systemctl {$action} 'nginx'");
})->with([
    ['enable', true],
    ['disable', false],
]);

test('control rejects an invalid action', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $service = Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'unknown',
    ]);

    $response = $this->post(route('services.control', $service), [
        'action' => 'rm -rf /',
    ]);

    $response->assertSessionHasErrors('action');
    expect($server->agentJobs()->where('type', 'shell')->count())->toBe(0);
});

test('refresh status dispatches a shell job without changing the stored status', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $service = Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'unknown',
    ]);

    $response = $this->post(route('services.refresh-status', $service));

    $response->assertRedirect(route('services.index', $server));

    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['params']['command'])->toContain('systemctl is-active');
    expect($published[0]['payload']['params']['command'])->toContain('systemctl is-enabled');
    expect($service->refresh()->status)->toBe('unknown');
});

test('destroy removes the service without dispatching a job', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $service = Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'unknown',
    ]);

    $response = $this->delete(route('services.destroy', $service));

    $response->assertRedirect(route('services.index', $server));
    expect(Service::find($service->id))->toBeNull();
    expect($server->agentJobs()->count())->toBe(0);
});

test('index renders with services and jobs props', function () {
    mockServiceGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $serviceA = Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'active',
        'config' => ['enabled' => true],
    ]);
    Service::create([
        'server_id' => $server->id,
        'type' => 'systemd',
        'name' => 'mysql',
        'status' => 'inactive',
    ]);

    app(\App\Services\ServiceManager::class)->control($serviceA, 'restart');

    $response = $this->get(route('services.index', $server));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('services', 2)
        ->has('jobs', 1)
        ->where('server.name', $server->name)
        ->where('services.0.name', 'mysql')
        ->where('services.1.name', 'nginx')
    );
});
