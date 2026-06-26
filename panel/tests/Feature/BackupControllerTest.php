<?php

use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupSetting;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockBackupControllerPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

function makeApp(): array
{
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'env_content' => "DB_CONNECTION=mysql\nDB_DATABASE=mydb\nDB_USERNAME=u\nDB_PASSWORD=p\n",
    ]);

    return [$user, $app, $server];
}

test('guests are redirected to the login page', function () {
    [$user, $app] = makeApp();

    $this->get(route('backups.index', $app))->assertRedirect('/login');
    $this->post(route('backups.store', $app))->assertRedirect('/login');
});

test('the backups page lists backups and settings', function () {
    [$user, $app] = makeApp();
    BackupSetting::create(['application_id' => $app->id, 'schedule' => 'daily']);
    Backup::create([
        'application_id' => $app->id, 'server_id' => $app->server_id,
        'status' => 'succeeded', 'type' => 'manual', 'size_bytes' => 1024,
    ]);

    $this->actingAs($user)
        ->get(route('backups.index', $app))
        ->assertInertia(fn ($page) => $page
            ->component('apps/backups')
            ->has('backups', 1)
            ->where('backups.0.status', 'succeeded')
            ->where('backups.0.size_bytes', 1024)
            ->where('settings.schedule', 'daily')
        );
});

test('manual backup triggers a job', function () {
    mockBackupControllerPublish();

    [$user, $app] = makeApp();

    $this->actingAs($user)
        ->post(route('backups.store', $app))
        ->assertRedirect(route('backups.index', $app));

    expect($app->backups()->count())->toBe(1);
    expect($app->backups()->first()->type)->toBe('manual');
});

test('settings update changes the schedule', function () {
    [$user, $app] = makeApp();

    $this->actingAs($user)
        ->post(route('backups.settings', $app), [
            'schedule' => 'weekly',
            'retention_count' => 4,
            'include_database' => true,
            'include_files' => true,
            'storage_local' => true,
            'storage_s3' => false,
        ])
        ->assertRedirect(route('backups.index', $app));

    $settings = $app->backupSetting;
    expect($settings->schedule)->toBe('weekly')
        ->and($settings->retention_count)->toBe(4);
});

test('settings validates schedule value', function () {
    [$user, $app] = makeApp();

    $this->actingAs($user)
        ->from(route('backups.index', $app))
        ->post(route('backups.settings', $app), [
            'schedule' => 'hourly',
            'retention_count' => 7,
        ])
        ->assertSessionHasErrors('schedule');
});

test('delete backup removes the row', function () {
    [$user, $app] = makeApp();
    $backup = Backup::create([
        'application_id' => $app->id, 'server_id' => $app->server_id,
        'status' => 'succeeded',
    ]);

    $this->actingAs($user)
        ->delete(route('backups.destroy', [$app, $backup]))
        ->assertRedirect(route('backups.index', $app));

    expect(Backup::find($backup->id))->toBeNull();
});

test('restore dispatches a job for a succeeded backup', function () {
    mockBackupControllerPublish();

    [$user, $app] = makeApp();
    $backup = Backup::create([
        'application_id' => $app->id, 'server_id' => $app->server_id,
        'status' => 'succeeded', 'local_path' => '/srv/velink-backups/app/bk.tar.gz',
    ]);

    $this->actingAs($user)
        ->post(route('backups.restore', [$app, $backup]))
        ->assertRedirect(route('backups.index', $app));

    expect(\App\Models\AgentJob::where('application_id', $app->id)->where('type', 'shell')->exists())->toBeTrue();
});

test('restore rejects a non-succeeded backup', function () {
    [$user, $app] = makeApp();
    $backup = Backup::create([
        'application_id' => $app->id, 'server_id' => $app->server_id,
        'status' => 'failed',
    ]);

    $this->actingAs($user)
        ->post(route('backups.restore', [$app, $backup]))
        ->assertStatus(422);
});
