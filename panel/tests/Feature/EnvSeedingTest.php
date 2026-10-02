<?php

use App\Models\Application;
use App\Provisioning\EnvTemplates;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockGatewayPublishEnv(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

function createApp(
    string $app_type = 'laravel',
    string $stack_mode = 'production',
    bool $create_database = true,
    ?string $domain = 'seeded.example.com',
    ?string $php_version = '8.3',
): Application {
    mockGatewayPublishEnv();

    test()->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $payload = [
        'name' => 'Seeded App',
        'domain' => $domain,
        'app_type' => $app_type,
        'stack_mode' => $stack_mode,
        'branch' => 'main',
    ];

    if ($php_version !== null) {
        $payload['php_version'] = $php_version;
    }

    if ($create_database) {
        $server->services()->create(['name' => 'mariadb', 'type' => 'database', 'status' => 'running']);
        $payload['create_database'] = true;
        $payload['db_engine'] = 'mariadb';
        $payload['db_name'] = 'seed_db';
        $payload['db_username'] = 'seed_user';
    }

    test()->post(route('applications.store', $server), $payload);

    return Application::firstWhere('domain', $domain);
}

test('a laravel app is seeded with full .env.example-style defaults plus the provisioned database', function () {
    $app = createApp();
    expect($app)->not->toBeNull();

    $env = $app->env_content;
    expect($env)->toContain('APP_NAME="Seeded App"');
    expect($env)->toContain('APP_ENV=production');
    expect($env)->toContain('APP_DEBUG=false');
    expect($env)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m');
    expect($env)->toContain('APP_URL=https://seeded.example.com');
    expect($env)->toContain('LOG_CHANNEL=stack');

    // Laravel 12 .env.example drivers when a database exists.
    expect($env)->toContain('SESSION_DRIVER=database');
    expect($env)->toContain('QUEUE_CONNECTION=database');
    expect($env)->toContain('CACHE_STORE=database');

    // The DB block carries the credentials provisioned with the app.
    expect($env)->toContain('DB_CONNECTION=mysql');
    expect($env)->toContain('DB_HOST=localhost');
    expect($env)->toContain('DB_PORT=3306');
    expect($env)->toContain('DB_DATABASE=seed_db');
    expect($env)->toContain('DB_USERNAME=seed_user');
    expect($env)->toMatch('/DB_PASSWORD=.+/');
});

test('a laravel app without a database still gets base defaults and no DB block', function () {
    $app = createApp(create_database: false);

    expect($app)->not->toBeNull();
    $env = $app->env_content;
    expect($env)->toContain('APP_KEY=base64:');
    expect($env)->toContain('APP_URL=https://seeded.example.com');

    // No database → drivers must not depend on one.
    expect($env)->toContain('SESSION_DRIVER=file');
    expect($env)->toContain('QUEUE_CONNECTION=sync');
    expect($env)->toContain('CACHE_STORE=file');
    expect($env)->not->toMatch('/^DB_/m');
});

test('a development-stack app gets local debugging defaults', function () {
    $app = createApp(stack_mode: 'development');

    $env = $app->env_content;
    expect($env)->toContain('APP_ENV=local');
    expect($env)->toContain('APP_DEBUG=true');
    expect($env)->toContain('LOG_LEVEL=debug');
});

test('a postgres database seeds the pgsql connection block', function () {
    $server = Server::factory()->online()->create();
    $server->services()->create(['name' => 'postgresql', 'type' => 'database', 'status' => 'running']);

    mockGatewayPublishEnv();
    $this->actingAs(User::factory()->create());
    $this->post(route('applications.store', $server), [
        'name' => 'Pg App',
        'domain' => 'pg.example.com',
        'app_type' => 'laravel',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'main',
        'create_database' => true,
        'db_engine' => 'postgres',
        'db_name' => 'pg_db',
        'db_username' => 'pg_user',
    ]);

    $env = Application::firstWhere('domain', 'pg.example.com')->env_content;
    expect($env)->toContain('DB_CONNECTION=pgsql');
    expect($env)->toContain('DB_HOST=127.0.0.1');
    expect($env)->toContain('DB_PORT=5432');
    expect($env)->toContain('DB_DATABASE=pg_db');
});

test('a custom php app gets generic defaults plus the database block', function () {
    $app = createApp(app_type: 'custom');

    $env = $app->env_content;
    expect($env)->toContain('APP_NAME="Seeded App"');
    expect($env)->toContain('APP_ENV=production');
    expect($env)->toContain('APP_URL=https://seeded.example.com');
    expect($env)->toContain('DB_DATABASE=seed_db');
    // Framework-specific keys stay out of generic apps.
    expect($env)->not->toMatch('/^APP_KEY=/m');
    expect($env)->not->toMatch('/^SESSION_DRIVER=/m');
});

test('wordpress and static apps are not seeded with a .env', function () {
    $wordpress = createApp(app_type: 'wordpress', domain: 'wp.example.com');
    expect($wordpress->env_content)->toBeNull();

    $static = createApp(app_type: 'static', create_database: false, domain: 'static.example.com', php_version: null);
    expect($static->env_content)->toBeNull();
});

test('an app name with quotes and newlines cannot corrupt the seeded env', function () {
    $app = new Application([
        'name' => "Shop \"Elite\"\nEVIL_INJECTED=pwned\t tab",
        'domain' => 'hostile.example.com',
        'app_type' => 'laravel',
        'stack_mode' => 'production',
    ]);

    $env = EnvTemplates::forApplication($app, null);

    // One inert quoted value on a single line — no quote breakout, no
    // smuggled EVIL_INJECTED variable, no raw newline inside the value.
    expect($env)->toContain("APP_NAME=\"Shop Elite EVIL_INJECTED=pwned tab\"\n");
    expect(preg_match('/^APP_NAME=/m', $env))->toBe(1);
    expect($env)->not->toMatch('/^EVIL_INJECTED=/m');
});
