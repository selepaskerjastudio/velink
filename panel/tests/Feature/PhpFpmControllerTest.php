<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\PhpPool;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\AppTemplates;
use App\Provisioning\PhpSettings;
use App\Services\AppProvisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockFpmGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

/** A fully-valid payload with a couple of overrides. */
function fpmPayload(array $overrides = []): array
{
    return array_map('strval', array_merge(PhpSettings::defaults(), [
        'pm_max_children' => 12,
        'memory_limit' => '512M',
    ], $overrides));
}

test('guests cannot update php settings', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $this->patch(route('applications.php-settings', $application), fpmPayload())
        ->assertRedirect('/login');
});

test('php settings update renders the pool and reloads fpm then logs audit', function () {
    mockFpmGatewayPublish();

    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'app_slug' => 'demo_app',
        'linux_user' => 'velink',
        'php_version' => '8.3',
        'status' => 'active',
        'name' => 'Demo App',
    ]);

    $response = $this->patch(route('applications.php-settings', $application), fpmPayload());

    $response->assertRedirect(route('applications.show', $application));

    // The settings were persisted.
    $application->refresh();
    expect($application->php_settings['pm_max_children'])->toBe('12');
    expect($application->php_settings['memory_limit'])->toBe('512M');

    // Two jobs dispatched in order: render_config to the pool path, then shell reload.
    $jobs = AgentJob::where('application_id', $application->id)->orderBy('id')->get();
    expect($jobs)->toHaveCount(2);

    expect($jobs[0]->type)->toBe('render_config');
    expect($jobs[0]->payload['path'])->toBe('/etc/php/8.3/fpm/pool.d/demo_app.conf');
    expect($jobs[0]->payload['template'])->toBe(AppTemplates::PHP_FPM_POOL);
    // The rendered vars carry the new settings.
    expect($jobs[0]->payload['vars']['pm_max_children'])->toBe('12');
    expect($jobs[0]->payload['vars']['memory_limit'])->toBe('512M');
    expect($jobs[0]->label)->toBe('Write PHP-FPM pool config');

    expect($jobs[1]->type)->toBe('shell');
    expect($jobs[1]->payload['command'])->toContain('systemctl reload php8.3-fpm');
    expect($jobs[1]->label)->toBe('Reload PHP-FPM');

    $log = AuditLog::where('action', 'application.php_settings_updated')->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->server_id)->toBe($server->id);
    expect($log->description)->toContain('Demo App');
});

test('updating php settings refreshes the active php pool config snapshot', function () {
    mockFpmGatewayPublish();

    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'app_slug' => 'demo_app',
        'php_version' => '8.3',
    ]);
    PhpPool::create([
        'application_id' => $application->id,
        'php_version' => '8.3',
        'pool_name' => 'demo_app',
        'socket_path' => '/run/php/demo_app.sock',
        'config' => [],
    ]);

    $this->patch(route('applications.php-settings', $application), fpmPayload(['memory_limit' => '256M']));

    $pool = $application->fresh()->phpPools()->first();
    expect($pool)->not->toBeNull();
    expect($pool->config['memory_limit'])->toBe('256M');
});

test('submitting the unchanged defaults is a no-op that dispatches no jobs', function () {
    mockFpmGatewayPublish();

    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'php_settings' => null, // defaults
    ]);

    $defaults = array_map('strval', PhpSettings::defaults());

    $this->patch(route('applications.php-settings', $application), $defaults)
        ->assertRedirect(route('applications.show', $application));

    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});

test('static apps are rejected with no jobs', function () {
    mockFpmGatewayPublish();

    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'app_type' => 'static',
    ]);

    $this->from(route('applications.show', $application))
        ->patch(route('applications.php-settings', $application), fpmPayload())
        ->assertSessionHasErrors('pm');

    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});

test('an invalid pm mode is rejected with validation errors and no jobs', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $this->from(route('applications.show', $application))
        ->patch(route('applications.php-settings', $application), fpmPayload(['pm' => 'bogus']))
        ->assertSessionHasErrors('pm');

    expect(AgentJob::where('application_id', $application->id)->count())->toBe(0);
});

test('the service re-render picks up stored settings in the pool config', function () {
    mockFpmGatewayPublish();

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'app_slug' => 'demo_app',
        'php_version' => '8.2',
    ]);

    $jobs = app(AppProvisionService::class)->updatePhpSettings($application, fpmPayload(['pm' => 'static', 'pm_max_children' => 7, 'memory_limit' => '1G']), null);

    expect($jobs)->toHaveCount(2);
    $render = collect($jobs)->firstWhere('type', 'render_config');
    expect($render->payload['vars']['pm'])->toBe('static');
    expect($render->payload['vars']['pm_max_children'])->toBe('7');
    expect($render->payload['vars']['memory_limit'])->toBe('1G');

    // The persisted column reflects the override.
    expect($application->fresh()->php_settings['pm'])->toBe('static');
});
