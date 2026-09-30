<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockGatewayPublishImport(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

function appWithDatabase(string $envContent = "APP_NAME=Shop\nDB_CONNECTION=mysql\nDB_HOST=localhost\nDB_PORT=3306\nDB_DATABASE=shop_db\nDB_USERNAME=shop_user\nDB_PASSWORD=s3cret!\n"): Application
{
    $server = Server::factory()->online()->create();

    return Application::factory()->create([
        'server_id' => $server->id,
        'env_content' => $envContent,
    ]);
}

function importDump(Application $app, ?string $filename = 'dump.sql', ?string $content = "INSERT INTO orders ...\n")
{
    return test()->post(route('applications.database.import', $app), [
        'dump' => UploadedFile::fake()->createWithContent($filename, $content),
    ]);
}

test('a member assigned to the server can import a sql dump into the app database', function () {
    mockGatewayPublishImport();
    $app = appWithDatabase();

    $member = User::factory()->member()->create();
    $member->servers()->attach($app->server);
    $this->actingAs($member);

    importDump($app)->assertRedirect(route('backups.index', $app));

    // 1. The dump is uploaded to a private temp file on the server.
    $upload = $app->server->agentJobs()->where('type', 'write_file')->where('label', 'Upload database dump')->first();
    expect($upload)->not->toBeNull();
    expect($upload->payload['path'])->toStartWith('/tmp/velink-import-');
    expect($upload->payload['mode'])->toBe('0600');
    expect($upload->payload['content'])->toBe("INSERT INTO orders ...\n");

    // 2. The import runs as the app's dedicated database user (never root),
    //    then the temp file is removed.
    $import = $app->server->agentJobs()->where('type', 'shell')->where('label', 'Import database')->first();
    expect($import)->not->toBeNull();
    expect($import->payload['command'])->toContain("mysql -u 'shop_user' -p's3cret!' -h localhost 'shop_db' <");
    expect($import->payload['command'])->toContain($upload->payload['path']);
    expect($import->payload['command'])->toContain('rm -f');
    expect($import->payload['timeout'])->toBe(1800);

    expect(AuditLog::where('action', 'database.imported')->where('server_id', $app->server_id)->exists())->toBeTrue();
});

test('a member not assigned to the server cannot import', function () {
    $app = appWithDatabase();

    $member = User::factory()->member()->create();
    $this->actingAs($member);

    importDump($app)->assertForbidden();
});

test('a postgres app imports via psql as the app user', function () {
    mockGatewayPublishImport();
    $app = appWithDatabase("DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=pg_db\nDB_USERNAME=pg_user\nDB_PASSWORD=pg pass\n");

    $this->actingAs(User::factory()->admin()->create());

    importDump($app)->assertRedirect();

    $import = $app->server->agentJobs()->where('type', 'shell')->where('label', 'Import database')->first();
    expect($import)->not->toBeNull();
    expect($import->payload['command'])->toContain("PGPASSWORD='pg pass' psql -U 'pg_user' -h 127.0.0.1 -d 'pg_db' -f");
    expect($import->payload['command'])->toContain('ON_ERROR_STOP=1');
});

test('an app without database credentials in its env is rejected', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create(['server_id' => $server->id, 'env_content' => "APP_NAME=NoDb\n"]);

    $this->actingAs(User::factory()->admin()->create());

    importDump($app)->assertSessionHasErrors('dump');
});

test('an unsupported database connection is rejected', function () {
    mockGatewayPublishImport();
    $app = appWithDatabase("DB_CONNECTION=sqlite\nDB_DATABASE=database.sqlite\n");

    $this->actingAs(User::factory()->admin()->create());

    importDump($app)->assertSessionHasErrors('dump');
});

test('only sql text dumps are accepted', function () {
    $app = appWithDatabase();

    $this->actingAs(User::factory()->admin()->create());

    importDump($app, 'dump.png', 'binary')->assertSessionHasErrors('dump');
    importDump($app, 'dump.sql.gz', 'gz')->assertSessionHasErrors('dump');
});
