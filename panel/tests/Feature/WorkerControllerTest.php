<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockWorkerGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}


test('guests are redirected to the login page', function () {
    $application = Application::factory()->create();
    $worker = Service::create([
        'server_id' => $application->server_id,
        'application_id' => $application->id,
        'type' => 'supervisor',
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'status' => 'unknown',
        'config' => ['numprocs' => 1],
    ]);

    $this->get(route('workers.index', $application))->assertRedirect('/login');
    $this->post(route('workers.store', $application), [])->assertRedirect('/login');
    $this->patch(route('workers.update', $worker), [])->assertRedirect('/login');
    $this->post(route('workers.control', $worker), [])->assertRedirect('/login');
    $this->delete(route('workers.destroy', $worker))->assertRedirect('/login');
});

test('a worker can be created', function () {
    mockWorkerGatewayPublish();

    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();

    $response = $this->post(route('workers.store', $application), [
        'name' => 'default',
        'command' => 'php artisan queue:work --tries=3',
        'numprocs' => 2,
    ]);

    $response->assertRedirect(route('workers.index', $application));

    $worker = Service::where('application_id', $application->id)->where('name', 'default')->first();
    expect($worker)->not->toBeNull();
    expect($worker->type)->toBe('supervisor');
    expect($worker->server_id)->toBe($application->server_id);
    expect($worker->command)->toBe('php artisan queue:work --tries=3');
    expect($worker->config)->toBe(['numprocs' => 2]);
    expect($worker->status)->toBe('running');

    expect($application->server->agentJobs()->count())->toBe(3);
    expect($application->server->agentJobs()->where('type', 'render_config')->count())->toBe(1);
    expect($application->server->agentJobs()->where('type', 'shell')->count())->toBe(2);
});

test('creating a worker renders the supervisor config with the expected vars', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();

    $this->post(route('workers.store', $application), [
        'name' => 'default',
        'command' => 'php artisan queue:work --tries=3',
        'numprocs' => 2,
    ]);

    $renderJob = collect($published)->firstWhere('payload.action', 'render_config');

    expect($renderJob)->not->toBeNull();
    expect($renderJob['payload']['params']['vars']['command'])->toBe('php artisan queue:work --tries=3');
    expect($renderJob['payload']['params']['vars']['directory'])->toBe($application->root_path);
    expect($renderJob['payload']['params']['vars']['linux_user'])->toBe($application->linux_user);
    expect($renderJob['payload']['params']['vars']['numprocs'])->toBe('2');
    expect($renderJob['payload']['params']['path'])->toContain('/etc/supervisor/conf.d/');

    $startJob = collect($published)->last();
    expect($startJob['payload']['params']['command'])->toContain('supervisorctl start');
});

test('creating a worker rejects invalid program names', function () {
    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();

    $response = $this->post(route('workers.store', $application), [
        'name' => 'Default Worker',
        'command' => 'php artisan queue:work',
        'numprocs' => 1,
    ]);
    $response->assertSessionHasErrors('name');

    $response = $this->post(route('workers.store', $application), [
        'name' => 'default; rm -rf /',
        'command' => 'php artisan queue:work',
        'numprocs' => 1,
    ]);
    $response->assertSessionHasErrors('name');

    expect(Service::where('application_id', $application->id)->count())->toBe(0);
});

test('worker names must be unique per application', function () {
    mockWorkerGatewayPublish();

    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();

    Service::create([
        'server_id' => $application->server_id,
        'application_id' => $application->id,
        'type' => 'supervisor',
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'status' => 'running',
        'config' => ['numprocs' => 1],
    ]);

    $response = $this->post(route('workers.store', $application), [
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'numprocs' => 1,
    ]);

    $response->assertSessionHasErrors('name');
    expect(Service::where('application_id', $application->id)->where('name', 'default')->count())->toBe(1);
});

test('the same worker name can be used on different applications', function () {
    mockWorkerGatewayPublish();

    $this->actingAs(User::factory()->create());
    $appA = Application::factory()->create();
    $appB = Application::factory()->create();

    Service::create([
        'server_id' => $appA->server_id,
        'application_id' => $appA->id,
        'type' => 'supervisor',
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'status' => 'running',
        'config' => ['numprocs' => 1],
    ]);

    $response = $this->post(route('workers.store', $appB), [
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'numprocs' => 1,
    ]);

    $response->assertRedirect(route('workers.index', $appB));
    expect(Service::where('application_id', $appB->id)->where('name', 'default')->exists())->toBeTrue();
});

test('a worker can be updated', function () {
    mockWorkerGatewayPublish();

    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();
    $worker = Service::create([
        'server_id' => $application->server_id,
        'application_id' => $application->id,
        'type' => 'supervisor',
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'status' => 'stopped',
        'config' => ['numprocs' => 1],
    ]);

    $response = $this->patch(route('workers.update', $worker), [
        'command' => 'php artisan queue:work --tries=5',
        'numprocs' => 3,
    ]);

    $response->assertRedirect();

    $worker->refresh();
    expect($worker->command)->toBe('php artisan queue:work --tries=5');
    expect($worker->config)->toBe(['numprocs' => 3]);
    expect($worker->status)->toBe('running');
});

test('control dispatches a shell job and updates status', function ($action, $expectedStatus) {
    mockWorkerGatewayPublish();

    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();
    $worker = Service::create([
        'server_id' => $application->server_id,
        'application_id' => $application->id,
        'type' => 'supervisor',
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'status' => 'unknown',
        'config' => ['numprocs' => 1],
    ]);

    $response = $this->post(route('workers.control', $worker), ['action' => $action]);

    $response->assertRedirect();
    expect($worker->refresh()->status)->toBe($expectedStatus);

    $job = $application->server->agentJobs()->where('type', 'shell')->first();
    expect($job)->not->toBeNull();
    expect($job->payload['command'])->toContain("supervisorctl {$action}");
})->with([
    ['start', 'running'],
    ['restart', 'running'],
    ['stop', 'stopped'],
]);

test('control rejects an invalid action', function () {
    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();
    $worker = Service::create([
        'server_id' => $application->server_id,
        'application_id' => $application->id,
        'type' => 'supervisor',
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'status' => 'unknown',
        'config' => ['numprocs' => 1],
    ]);

    $response = $this->post(route('workers.control', $worker), ['action' => 'rm -rf /']);

    $response->assertSessionHasErrors('action');
    expect($application->server->agentJobs()->count())->toBe(0);
});

test('a worker can be deleted', function () {
    mockWorkerGatewayPublish();

    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();
    $worker = Service::create([
        'server_id' => $application->server_id,
        'application_id' => $application->id,
        'type' => 'supervisor',
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'status' => 'running',
        'config' => ['numprocs' => 1],
    ]);

    $response = $this->delete(route('workers.destroy', $worker));

    $response->assertRedirect(route('workers.index', $application));
    expect(Service::find($worker->id))->toBeNull();

    $job = $application->server->agentJobs()->where('type', 'shell')->first();
    expect($job)->not->toBeNull();
    expect($job->payload['command'])->toContain('supervisorctl stop');
});

test('update/control/destroy reject services that are not supervisor workers', function () {
    mockWorkerGatewayPublish();

    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();
    $service = Service::create([
        'server_id' => $application->server_id,
        'application_id' => $application->id,
        'type' => 'systemd',
        'name' => 'nginx',
        'status' => 'unknown',
    ]);

    $this->patch(route('workers.update', $service), ['command' => 'x', 'numprocs' => 1])->assertNotFound();
    $this->post(route('workers.control', $service), ['action' => 'start'])->assertNotFound();
    $this->delete(route('workers.destroy', $service))->assertNotFound();
});

test('index renders with workers and jobs props', function () {
    mockWorkerGatewayPublish();

    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();

    Service::create([
        'server_id' => $application->server_id,
        'application_id' => $application->id,
        'type' => 'supervisor',
        'name' => 'default',
        'command' => 'php artisan queue:work',
        'status' => 'running',
        'config' => ['numprocs' => 1],
    ]);

    $response = $this->get(route('workers.index', $application));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('workers', 1)
        ->where('application.name', $application->name)
        ->where('server.name', $application->server->name)
        ->where('workers.0.name', 'default')
    );
});
