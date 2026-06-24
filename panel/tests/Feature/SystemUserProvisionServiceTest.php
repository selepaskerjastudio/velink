<?php

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\SystemUserProvisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockSystemUserPublish(): array
{
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);
        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    return $published;
}

function lastShellCommand(Server $server): string
{
    // payload is cast to encrypted:array, so we must hydrate the model to read
    // the decoded command — raw DB access would see the encrypted blob.
    $job = AgentJob::where('server_id', $server->id)
        ->where('type', 'shell')
        ->latest('id')
        ->first();

    return $job?->payload['command'] ?? '';
}

test('create dispatches a useradd + chpasswd + optional sudo shell job', function () {
    $published = mockSystemUserPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    app(SystemUserProvisionService::class)->create(
        server: $server,
        username: 'deployer',
        shell: '/bin/bash',
        isSudo: true,
        userId: $user->id,
    );

    $command = lastShellCommand($server);
    expect($command)->toContain('useradd --create-home --shell /bin/bash deployer')
        ->and($command)->toContain('chpasswd')
        ->and($command)->toContain('usermod -aG sudo deployer');

    // The system_users row is persisted with the requested attributes.
    $systemUser = SystemUser::where('server_id', $server->id)->where('username', 'deployer')->first();
    expect($systemUser)->not->toBeNull()
        ->and($systemUser->shell)->toBe('/bin/bash')
        ->and($systemUser->is_sudo)->toBeTrue();
});

test('create without sudo omits the usermod command', function () {
    mockSystemUserPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    app(SystemUserProvisionService::class)->create(
        server: $server,
        username: 'dev',
        shell: '/bin/sh',
        isSudo: false,
        userId: $user->id,
    );

    $command = lastShellCommand($server);
    expect($command)->toContain('useradd --create-home --shell /bin/sh dev')
        ->and($command)->not->toContain('usermod -aG sudo');
});

test('updateSudo dispatches gpasswd and flips the flag', function () {
    mockSystemUserPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $systemUser = SystemUser::create([
        'server_id' => $server->id, 'username' => 'dev',
        'shell' => '/bin/bash', 'is_sudo' => false,
    ]);

    app(SystemUserProvisionService::class)->updateSudo($systemUser, isSudo: true, userId: $user->id);

    expect(lastShellCommand($server))->toContain('gpasswd -a dev sudo');
    expect($systemUser->refresh()->is_sudo)->toBeTrue();

    app(SystemUserProvisionService::class)->updateSudo($systemUser, isSudo: false, userId: $user->id);

    expect(lastShellCommand($server))->toContain('gpasswd -d dev sudo');
    expect($systemUser->refresh()->is_sudo)->toBeFalse();
});

test('updateShell dispatches chsh and persists the new shell', function () {
    mockSystemUserPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $systemUser = SystemUser::create([
        'server_id' => $server->id, 'username' => 'dev',
        'shell' => '/bin/bash', 'is_sudo' => false,
    ]);

    app(SystemUserProvisionService::class)->updateShell($systemUser, shell: '/usr/sbin/nologin', userId: $user->id);

    expect(lastShellCommand($server))->toContain('chsh -s /usr/sbin/nologin dev');
    expect($systemUser->refresh()->shell)->toBe('/usr/sbin/nologin');
});

test('delete dispatches userdel --remove and removes the record', function () {
    mockSystemUserPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $systemUser = SystemUser::create([
        'server_id' => $server->id, 'username' => 'dev',
        'shell' => '/bin/bash', 'is_sudo' => false,
    ]);

    app(SystemUserProvisionService::class)->delete($systemUser, userId: $user->id);

    expect(lastShellCommand($server))->toContain('userdel --remove dev');
    expect(SystemUser::find($systemUser->id))->toBeNull();
});
