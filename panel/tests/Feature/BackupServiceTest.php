<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupSetting;
use App\Models\Server;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function lastBackupCommand(string $applicationId): string
{
    // Payload is cast to encrypted:array — hydrate the model to read the decoded command.
    $job = AgentJob::where('application_id', $applicationId)
        ->where('type', 'shell')
        ->latest('id')
        ->first();

    return $job?->payload['command'] ?? '';
}

function mockBackupPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

function makeBackupApp(): array
{
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'root_path' => '/home/velink/webapps/myapp',
        'app_slug' => 'myapp',
        'env_content' => "APP_NAME=MyApp\nDB_CONNECTION=mysql\nDB_DATABASE=myapp_db\nDB_USERNAME=myapp_user\nDB_PASSWORD=secret123\n",
    ]);
    $settings = BackupSetting::create(['application_id' => $app->id]);

    return [$app, $server, $settings];
}

test('parse_db_credentials extracts connection database user and password', function () {
    $service = app(BackupService::class);

    $result = $service->parseDbCredentials("DB_CONNECTION=pgsql\nDB_DATABASE=mydb\nDB_USERNAME=\"myuser\"\nDB_PASSWORD='mypass'\n");

    expect($result['connection'])->toBe('pgsql')
        ->and($result['database'])->toBe('mydb')
        ->and($result['username'])->toBe('myuser')
        ->and($result['password'])->toBe('mypass');
});

test('parse_db_credentials returns nulls when env content is empty', function () {
    $result = app(BackupService::class)->parseDbCredentials(null);

    expect($result['connection'])->toBeNull()
        ->and($result['database'])->toBeNull();
});

test('run_backup creates a backup row and dispatches a shell job', function () {
    mockBackupPublish();

    [$app, $server, $settings] = makeBackupApp();
    $user = User::factory()->create();

    $backup = app(BackupService::class)->runBackup($app, $settings, 'manual', $user->id);

    expect($backup)->toBeInstanceOf(Backup::class)
        ->and($backup->status)->toBe(Backup::STATUS_RUNNING)
        ->and($backup->type)->toBe('manual')
        ->and($backup->agent_job_id)->not->toBeNull();

    // A shell job was dispatched.
    expect(AgentJob::where('application_id', $app->id)->where('type', 'shell')->exists())->toBeTrue();
});

test('backup script contains mysqldump for mysql engine', function () {
    mockBackupPublish();

    [$app, $server, $settings] = makeBackupApp();
    $user = User::factory()->create();

    app(BackupService::class)->runBackup($app, $settings, 'manual', $user->id);

    $command = lastBackupCommand($app->id);

    expect($command)->toContain('mysqldump')
        ->and($command)->toContain('myapp_db');
});

test('backup script contains pg_dump for postgres engine', function () {
    mockBackupPublish();

    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'root_path' => '/home/velink/webapps/pgapp',
        'app_slug' => 'pgapp',
        'env_content' => "DB_CONNECTION=pgsql\nDB_DATABASE=pgdb\nDB_USERNAME=pguser\nDB_PASSWORD=secret\n",
    ]);
    $settings = BackupSetting::create(['application_id' => $app->id]);
    $user = User::factory()->create();

    app(BackupService::class)->runBackup($app, $settings, 'manual', $user->id);

    $command = lastBackupCommand($app->id);

    expect($command)->toContain('pg_dump')
        ->and($command)->toContain('pgdb');
});

test('backup script tars the app root_path', function () {
    mockBackupPublish();

    [$app, $server, $settings] = makeBackupApp();
    $user = User::factory()->create();

    app(BackupService::class)->runBackup($app, $settings, 'manual', $user->id);

    $command = lastBackupCommand($app->id);

    expect($command)->toContain('/home/velink/webapps/myapp')
        ->and($command)->toContain('tar czf')
        ->and($command)->toContain('files.tar.gz');
});

test('backup without database skips mysqldump', function () {
    mockBackupPublish();

    [$app, $server, $settings] = makeBackupApp();
    $settings->update(['include_database' => false]);
    $user = User::factory()->create();

    app(BackupService::class)->runBackup($app, $settings, 'manual', $user->id);

    $command = lastBackupCommand($app->id);

    expect($command)->not->toContain('mysqldump')
        ->and($command)->toContain('files.tar.gz'); // files still backed up
});

test('backup script echoes size for panel parsing', function () {
    mockBackupPublish();

    [$app, $server, $settings] = makeBackupApp();
    $user = User::factory()->create();

    app(BackupService::class)->runBackup($app, $settings, 'manual', $user->id);

    $command = lastBackupCommand($app->id);

    expect($command)->toContain('BACKUP_SIZE=');
});

test('prune_old_backups deletes beyond retention count', function () {
    mockBackupPublish();

    [$app, $server, $settings] = makeBackupApp();

    // Create 10 succeeded backups.
    for ($i = 0; $i < 10; $i++) {
        Backup::create([
            'application_id' => $app->id,
            'server_id' => $server->id,
            'status' => Backup::STATUS_SUCCEEDED,
            'local_path' => "/srv/velink-backups/myapp/bk-{$i}.tar.gz",
        ]);
    }

    app(BackupService::class)->pruneOldBackups($app, retentionCount: 5);

    // Only 5 remain (the 5 most recent).
    expect($app->backups()->count())->toBe(5);
});

test('restore_backup dispatches extraction and db import jobs', function () {
    mockBackupPublish();

    [$app, $server, $settings] = makeBackupApp();
    $user = User::factory()->create();

    $backup = Backup::create([
        'application_id' => $app->id,
        'server_id' => $server->id,
        'status' => Backup::STATUS_SUCCEEDED,
        'local_path' => '/srv/velink-backups/myapp/backup.tar.gz',
    ]);

    app(BackupService::class)->restoreBackup($backup, $user->id);

    $command = lastBackupCommand($app->id);

    expect($command)->toContain('tar xzf')
        ->and($command)->toContain('gunzip')
        ->and($command)->toContain('mysql')
        ->and($command)->toContain('myapp_db');
});
