<?php

use App\Models\Application;
use App\Models\PhpPool;
use App\Models\Server;
use App\Provisioning\AppTemplates;
use App\Services\AppProvisionService;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('app templates expose the expected vars and paths', function () {
    $application = Application::factory()->create([
        'domain' => 'example.test',
        'root_path' => '/home/example_app',
        'linux_user' => 'example_app',
        'php_version' => '8.3',
    ]);

    $vars = AppTemplates::vars($application);

    expect($vars)->toBe([
        'domain' => 'example.test',
        'root_path' => '/home/example_app',
        'linux_user' => 'example_app',
        'pool_name' => 'example_app',
        'socket_path' => '/run/php/example_app.sock',
    ]);

    expect(AppTemplates::poolConfigPath('8.3', 'example_app'))->toBe('/etc/php/8.3/fpm/pool.d/example_app.conf');
    expect(AppTemplates::vhostPath('example.test'))->toBe('/etc/nginx/sites-available/example.test.conf');
    expect(AppTemplates::vhostEnabledPath('example.test'))->toBe('/etc/nginx/sites-enabled/example.test.conf');

    // Templates reference every var key with the Go text/template syntax.
    foreach (array_keys($vars) as $key) {
        if ($key === 'pool_name') {
            expect(AppTemplates::PHP_FPM_POOL)->toContain("{{.{$key}}}");

            continue;
        }

        expect(AppTemplates::NGINX_VHOST.AppTemplates::PHP_FPM_POOL)->toContain("{{.{$key}}}");
    }
});

test('provisionNew dispatches the expected job sequence and creates a php pool', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'example.test',
        'root_path' => '/home/example_app',
        'linux_user' => 'example_app',
        'php_version' => '8.3',
    ]);

    $jobs = app(AppProvisionService::class)->provisionNew($application);

    expect($jobs)->toHaveCount(6);
    expect(collect($jobs)->pluck('label')->all())->toBe([
        'Create Linux user',
        'Create app directory',
        'Write PHP-FPM pool config',
        'Reload PHP-FPM',
        'Write nginx vhost',
        'Enable site & reload nginx',
    ]);
    expect(collect($jobs)->pluck('application_id')->unique()->all())->toBe([$application->id]);

    expect($jobs[0]->payload['command'])->toContain('useradd')->toContain('example_app');
    expect($jobs[1]->payload['command'])->toContain('/home/example_app');

    expect($jobs[2]->type)->toBe('render_config');
    expect($jobs[2]->payload['path'])->toBe('/etc/php/8.3/fpm/pool.d/example_app.conf');
    expect($jobs[2]->payload['template'])->toBe(AppTemplates::PHP_FPM_POOL);
    expect($jobs[2]->payload['vars'])->toBe(AppTemplates::vars($application));

    expect($jobs[3]->payload['command'])->toContain('systemctl reload php8.3-fpm');

    expect($jobs[4]->type)->toBe('render_config');
    expect($jobs[4]->payload['path'])->toBe('/etc/nginx/sites-available/example.test.conf');
    expect($jobs[4]->payload['template'])->toBe(AppTemplates::NGINX_VHOST);

    expect($jobs[5]->payload['command'])->toContain('systemctl reload nginx');

    // Published envelopes carry the action + params for the agent.
    expect($published)->toHaveCount(6);
    expect($published[2]['payload']['action'])->toBe('render_config');

    $pool = PhpPool::where('application_id', $application->id)->first();
    expect($pool)->not->toBeNull();
    expect($pool->php_version)->toBe('8.3');
    expect($pool->pool_name)->toBe('example_app');
    expect($pool->socket_path)->toBe('/run/php/example_app.sock');
});

test('changePhpVersion dispatches the expected job sequence and updates state', function () {
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'example.test',
        'root_path' => '/home/example_app',
        'linux_user' => 'example_app',
        'php_version' => '8.3',
    ]);

    PhpPool::create([
        'application_id' => $application->id,
        'php_version' => '8.3',
        'pool_name' => 'example_app',
        'socket_path' => '/run/php/example_app.sock',
        'config' => AppTemplates::vars($application),
    ]);

    $jobs = app(AppProvisionService::class)->changePhpVersion($application, '8.4');

    expect($jobs)->toHaveCount(3);
    expect(collect($jobs)->pluck('label')->all())->toBe([
        'Remove old PHP-FPM pool',
        'Write PHP-FPM pool config (PHP 8.4)',
        'Reload PHP-FPM',
    ]);

    expect($jobs[0]->payload['command'])->toContain('/etc/php/8.3/fpm/pool.d/example_app.conf')
        ->toContain('systemctl reload php8.3-fpm');

    expect($jobs[1]->type)->toBe('render_config');
    expect($jobs[1]->payload['path'])->toBe('/etc/php/8.4/fpm/pool.d/example_app.conf');

    expect($jobs[2]->payload['command'])->toContain('systemctl reload php8.4-fpm');

    expect($application->refresh()->php_version)->toBe('8.4');

    $pool = PhpPool::where('application_id', $application->id)->first();
    expect($pool->php_version)->toBe('8.4');
});
