<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockNginxGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests cannot update nginx config', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $this->post(route('applications.nginx-config', $application), ['config' => 'server {}'])
        ->assertRedirect('/login');
});

test('nginx config update writes the file then reloads nginx and logs audit', function () {
    mockNginxGatewayPublish();

    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'name' => 'My App',
    ]);

    $config = "server {\n    listen 80;\n    server_name myapp.example.com;\n}\n";

    $response = $this->post(route('applications.nginx-config', $application), [
        'config' => $config,
    ]);

    $response->assertRedirect(route('applications.show', $application));

    // Two jobs dispatched in order: write_file to the sites-available path, then shell reload.
    $jobs = AgentJob::where('application_id', $application->id)->orderBy('id')->get();
    expect($jobs)->toHaveCount(2);

    expect($jobs[0]->type)->toBe('write_file');
    expect($jobs[0]->payload['path'])->toBe("/etc/nginx/sites-available/{$application->domain}.conf");
    // Compare trimmed so the assertion is robust to any trailing-newline
    // normalization the dispatcher may apply to file contents.
    expect(trim($jobs[0]->payload['content']))->toBe(trim($config));
    expect($jobs[0]->label)->toBe('Update NGINX config');

    expect($jobs[1]->type)->toBe('shell');
    expect($jobs[1]->payload['command'])->toContain('nginx -t');
    expect($jobs[1]->payload['command'])->toContain('systemctl reload nginx');
    expect($jobs[1]->label)->toBe('Reload NGINX');

    $log = AuditLog::where('action', 'application.nginx_config_updated')->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->server_id)->toBe($server->id);
    expect($log->description)->toContain('My App');
});

test('nginx config rejects a missing config field', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
    ]);

    $response = $this->from(route('applications.show', $application))
        ->post(route('applications.nginx-config', $application), []);

    $response->assertSessionHasErrors('config');
    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});

test('nginx config rejects content over the max length', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
    ]);

    $response = $this->from(route('applications.show', $application))
        ->post(route('applications.nginx-config', $application), [
            'config' => str_repeat('a', 65536),
        ]);

    $response->assertSessionHasErrors('config');
    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});
