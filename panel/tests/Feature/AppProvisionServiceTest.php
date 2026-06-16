<?php

use App\Models\Application;
use App\Models\PhpPool;
use App\Models\Server;
use App\Provisioning\AppTemplates;
use App\Services\AppProvisionService;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function mockProvisionPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('app templates expose the expected vars and paths', function () {
    $application = Application::factory()->create([
        'domain' => 'example.test',
        'app_type' => 'custom',
        'stack_mode' => 'production',
        'linux_user' => 'velink',
        'app_slug' => 'example_app',
        'root_path' => '/home/velink/webapps/example_app',
        'php_version' => '8.3',
    ]);

    $vars = AppTemplates::vars($application);

    expect($vars)->toBe([
        'domain' => 'example.test',
        'root_path' => '/home/velink/webapps/example_app',
        'web_root' => '/home/velink/webapps/example_app/public',
        'linux_user' => 'velink',
        'app_slug' => 'example_app',
        'pool_name' => 'example_app',
        'socket_path' => '/run/php/example_app.sock',
        'access_log' => '/home/velink/logs/example_app_access.log',
        'error_log' => '/home/velink/logs/example_app_error.log',
        'display_errors' => 'Off',
        'opcache_validate_timestamps' => '0',
    ]);

    expect(AppTemplates::poolConfigPath('8.3', 'example_app'))->toBe('/etc/php/8.3/fpm/pool.d/example_app.conf');
    expect(AppTemplates::vhostPath('example.test'))->toBe('/etc/nginx/sites-available/example.test.conf');
    expect(AppTemplates::vhostEnabledPath('example.test'))->toBe('/etc/nginx/sites-enabled/example.test.conf');

    // Every directly-templated var key is referenced by the relevant template
    // (Go text/template). app_slug is a convenience key backing pool_name /
    // socket_path / the log paths, so it is not itself interpolated.
    $allTemplates = AppTemplates::NGINX_VHOST.AppTemplates::PHP_FPM_POOL;
    foreach (array_keys($vars) as $key) {
        if ($key === 'app_slug') {
            continue;
        }
        expect($allTemplates)->toContain("{{.{$key}}}");
    }
});

test('web_root and stack_mode vars follow the app type and mode', function () {
    $static = Application::factory()->create(['app_type' => 'static', 'root_path' => '/home/velink/webapps/s', 'app_slug' => 's']);
    expect(AppTemplates::vars($static)['web_root'])->toBe('/home/velink/webapps/s/public');

    $wp = Application::factory()->create(['app_type' => 'wordpress', 'root_path' => '/home/velink/webapps/w', 'app_slug' => 'w']);
    expect(AppTemplates::vars($wp)['web_root'])->toBe('/home/velink/webapps/w');

    $dev = Application::factory()->create(['app_type' => 'custom', 'stack_mode' => 'development', 'app_slug' => 'd']);
    expect(AppTemplates::vars($dev)['display_errors'])->toBe('On');
    expect(AppTemplates::vars($dev)['opcache_validate_timestamps'])->toBe('1');
});

test('provisionNew (custom) dispatches the expected job sequence and creates a php pool', function () {
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
        'app_type' => 'custom',
        'linux_user' => 'velink',
        'app_slug' => 'example_app',
        'root_path' => '/home/velink/webapps/example_app',
        'php_version' => '8.3',
    ]);

    $jobs = app(AppProvisionService::class)->provisionNew($application);

    expect($jobs)->toHaveCount(5);
    expect(collect($jobs)->pluck('label')->all())->toBe([
        'Create web app directory',
        'Write PHP-FPM pool config',
        'Reload PHP-FPM',
        'Write nginx vhost',
        'Enable site & reload nginx',
    ]);
    expect(collect($jobs)->pluck('application_id')->unique()->all())->toBe([$application->id]);

    // The shared OS user is ensured and the app lives under webapps/.
    expect($jobs[0]->payload['command'])
        ->toContain('useradd')
        ->toContain('velink')
        ->toContain('/home/velink/webapps/example_app');

    expect($jobs[1]->type)->toBe('render_config');
    expect($jobs[1]->payload['path'])->toBe('/etc/php/8.3/fpm/pool.d/example_app.conf');
    expect($jobs[1]->payload['template'])->toBe(AppTemplates::PHP_FPM_POOL);

    expect($jobs[2]->payload['command'])->toContain('systemctl reload php8.3-fpm');

    expect($jobs[3]->type)->toBe('render_config');
    expect($jobs[3]->payload['path'])->toBe('/etc/nginx/sites-available/example.test.conf');
    expect($jobs[3]->payload['template'])->toBe(AppTemplates::NGINX_VHOST);

    expect($jobs[4]->payload['command'])->toContain('systemctl reload nginx');

    $pool = PhpPool::where('application_id', $application->id)->first();
    expect($pool)->not->toBeNull();
    expect($pool->pool_name)->toBe('example_app');
    expect($pool->socket_path)->toBe('/run/php/example_app.sock');
});

test('provisionNew (static) skips the php-fpm pool entirely', function () {
    mockProvisionPublish();

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'app_type' => 'static',
        'app_slug' => 'static_app',
        'root_path' => '/home/velink/webapps/static_app',
    ]);

    $jobs = app(AppProvisionService::class)->provisionNew($application);

    expect(collect($jobs)->pluck('label')->all())->toBe([
        'Create web app directory',
        'Write nginx vhost',
        'Enable site & reload nginx',
    ]);
    // Static vhost has no fastcgi pass.
    expect($jobs[1]->payload['template'])->toBe(AppTemplates::NGINX_VHOST_STATIC);
    expect($jobs[1]->payload['template'])->not->toContain('fastcgi_pass');
    expect(PhpPool::where('application_id', $application->id)->exists())->toBeFalse();
});

test('provisionNew (wordpress) downloads core and renders a secured wp-config', function () {
    mockProvisionPublish();

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'app_type' => 'wordpress',
        'app_slug' => 'blog',
        'root_path' => '/home/velink/webapps/blog',
        'php_version' => '8.3',
    ]);

    $dbCreds = ['name' => 'blog_db', 'user' => 'blog_user', 'password' => 'aBcD1234efGH', 'host' => 'localhost'];

    $jobs = app(AppProvisionService::class)->provisionNew($application, null, $dbCreds);

    expect(collect($jobs)->pluck('label')->all())->toBe([
        'Create web app directory',
        'Write PHP-FPM pool config',
        'Reload PHP-FPM',
        'Write nginx vhost',
        'Enable site & reload nginx',
        'Download WordPress core',
        'Write wp-config.php',
        'Secure wp-config.php',
    ]);

    expect($jobs[3]->payload['template'])->toBe(AppTemplates::NGINX_VHOST_WORDPRESS);

    $core = collect($jobs)->firstWhere('label', 'Download WordPress core');
    expect($core->payload['command'])->toContain('wordpress.org/latest.tar.gz');

    $wpConfig = collect($jobs)->firstWhere('label', 'Write wp-config.php');
    expect($wpConfig->type)->toBe('render_config');
    expect($wpConfig->payload['path'])->toBe('/home/velink/webapps/blog/wp-config.php');
    expect($wpConfig->payload['mode'])->toBe('0640');
    expect($wpConfig->payload['vars']['db_name'])->toBe('blog_db');
    expect($wpConfig->payload['vars']['db_user'])->toBe('blog_user');
    expect($wpConfig->payload['vars']['db_password'])->toBe('aBcD1234efGH');
    // Unique salts are generated.
    expect($wpConfig->payload['vars']['auth_key'])->not->toBe($wpConfig->payload['vars']['nonce_salt']);

    $secure = collect($jobs)->firstWhere('label', 'Secure wp-config.php');
    expect($secure->payload['command'])->toContain('chmod 640');
});

test('changePhpVersion dispatches the expected job sequence and updates state', function () {
    mockProvisionPublish();

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'example.test',
        'linux_user' => 'velink',
        'app_slug' => 'example_app',
        'root_path' => '/home/velink/webapps/example_app',
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

    $pool = PhpPool::where('application_id', $application->id)->where('pool_name', 'example_app')->first();
    expect($pool->php_version)->toBe('8.4');
});
