<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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
    Storage::fake();
    mockGatewayPublishImport();
    $app = appWithDatabase();

    $member = User::factory()->member()->create();
    $member->servers()->attach($app->server);
    $this->actingAs($member);

    importDump($app)->assertRedirect(route('backups.index', $app));

    // 1. The dump is streamed to panel storage (served back to the agent
    //    through a signed URL — large dumps never transit job payloads).
    $stored = array_values(Storage::files('dumps'));
    expect($stored)->toHaveCount(1);
    expect($stored[0])->toEndWith('.sql');
    expect(Storage::get($stored[0]))->toBe("INSERT INTO orders ...\n");

    // 2. A single shell job pulls the dump via the signed URL and imports it
    //    as the app's dedicated database user (never root), then cleans up.
    $import = $app->server->agentJobs()->where('type', 'shell')->where('label', 'Import database')->first();
    expect($import)->not->toBeNull();
    expect($import->payload['timeout'])->toBe(3600);
    expect($import->payload['command'])->toContain('curl -fsSL --retry 3 -o');
    expect($import->payload['command'])->toMatch('#https?://.*/dumps/[0-9a-f-]{36}\.sql\?expires=#');
    expect($import->payload['command'])->toContain("mysql -u 'shop_user' -p's3cret!' -h localhost 'shop_db' <");
    expect($import->payload['command'])->toContain('rm -f');

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

test('the dump download endpoint serves files only through valid signed urls', function () {
    Storage::fake();
    $filename = '0f0a1b2c-3d4e-5f60-7a8b-9c0d1e2f3a4b.sql';
    Storage::put("dumps/{$filename}", 'INSERT ...');

    // Unsigned request → rejected by the signed middleware.
    $this->get(route('dumps.download', ['dump' => $filename]))->assertForbidden();

    // Signed URL → the dump is streamed back as an SQL download.
    $url = URL::temporarySignedRoute('dumps.download', now()->addHour(), ['dump' => $filename]);
    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/sql');
});

test('dumps prune removes stored dumps older than a day', function () {
    Storage::fake();
    Storage::put('dumps/old.sql', 'old');
    Storage::put('dumps/fresh.sql', 'fresh');
    $oldPath = Storage::path('dumps/old.sql');
    touch($oldPath, now()->subDays(2)->getTimestamp());

    Artisan::call('dumps:prune');

    expect(Storage::exists('dumps/old.sql'))->toBeFalse();
    expect(Storage::exists('dumps/fresh.sql'))->toBeTrue();
});
