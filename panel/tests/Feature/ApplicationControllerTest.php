<?php

use App\Models\Application;
use App\Models\PhpPool;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function mockGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();

    $this->get(route('applications.create', $server))->assertRedirect('/login');
    $this->post(route('applications.store', $server), [])->assertRedirect('/login');
});

test('authenticated users can view the create application page', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $this->get(route('applications.create', $server))->assertOk();
});

test('an application can be created and provisions a php pool plus jobs', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('applications.store', $server), [
        'name' => 'My App',
        'domain' => 'my-app.example.com',
        'php_version' => '8.3',
    ]);

    $application = Application::firstWhere('domain', 'my-app.example.com');

    $response->assertRedirect(route('applications.show', $application));
    expect($application)->not->toBeNull();
    expect($application->server_id)->toBe($server->id);
    expect($application->name)->toBe('My App');
    expect($application->php_version)->toBe('8.3');
    expect($application->status)->toBe('provisioning');
    expect($application->linux_user)->toMatch('/^[a-z][a-z0-9_]*$/');
    expect($application->root_path)->toBe("/home/{$application->linux_user}");

    expect($application->server->agentJobs()->where('application_id', $application->id)->count())->toBe(6);
    expect(PhpPool::where('application_id', $application->id)->where('php_version', '8.3')->exists())->toBeTrue();
});

test('application domain must be unique', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    Application::factory()->create(['server_id' => $server->id, 'domain' => 'taken.example.com']);

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Other App',
        'domain' => 'taken.example.com',
        'php_version' => '8.3',
    ]);

    $response->assertSessionHasErrors('domain');
});

test('application domain must match the hostname format', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Bad App',
        'domain' => 'not a domain',
        'php_version' => '8.3',
    ]);

    $response->assertSessionHasErrors('domain');
});

test('php version must be a supported catalog version', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('applications.store', $server), [
        'name' => 'My App',
        'domain' => 'my-app.example.com',
        'php_version' => '5.6',
    ]);

    $response->assertSessionHasErrors('php_version');
});

test('an application show page can be viewed', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $this->get(route('applications.show', $application))->assertOk();
});

test('php version can be switched and dispatches the change job sequence', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id, 'php_version' => '8.3']);

    PhpPool::create([
        'application_id' => $application->id,
        'php_version' => '8.3',
        'pool_name' => $application->linux_user,
        'socket_path' => "/run/php/{$application->linux_user}.sock",
        'config' => [],
    ]);

    $response = $this->patch(route('applications.php-version', $application), [
        'php_version' => '8.4',
    ]);

    $response->assertRedirect(route('applications.show', $application));
    expect($application->refresh()->php_version)->toBe('8.4');
    expect($application->server->agentJobs()->where('application_id', $application->id)->count())->toBe(3);
});

test('switching to the current php version does not dispatch jobs', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id, 'php_version' => '8.3']);

    $response = $this->patch(route('applications.php-version', $application), [
        'php_version' => '8.3',
    ]);

    $response->assertRedirect(route('applications.show', $application));
    expect($application->server->agentJobs()->where('application_id', $application->id)->count())->toBe(0);
});

test('env content can be updated and is written to the server', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $response = $this->patch(route('applications.env', $application), [
        'env_content' => "APP_NAME=Test\nAPP_ENV=production",
    ]);

    $response->assertRedirect(route('applications.show', $application));
    expect($application->refresh()->env_content)->toBe("APP_NAME=Test\nAPP_ENV=production");

    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['action'])->toBe('write_file');
    expect($published[0]['payload']['params']['path'])->toBe("{$application->root_path}/.env");
});
