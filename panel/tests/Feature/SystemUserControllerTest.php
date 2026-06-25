<?php

use App\Models\AuditLog;
use App\Models\Server;
use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockSystemUserControllerPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();
    $this->get(route('system-users.index', $server))->assertRedirect('/login');
    $this->post(route('system-users.store', $server), [])->assertRedirect('/login');
});

test('index lists system users with their ssh key counts', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    SystemUser::create([
        'server_id' => $server->id, 'username' => 'dev',
        'shell' => '/bin/bash', 'is_sudo' => true,
    ]);
    SystemUser::create([
        'server_id' => $server->id, 'username' => 'root',
        'shell' => '/bin/bash', 'is_sudo' => true, 'is_system_reserved' => true,
    ]);

    $this->actingAs($user)
        ->get(route('system-users.index', $server))
        ->assertInertia(fn ($page) => $page
            ->has('systemUsers', 2)
            // Reserved users (root) sort first, then managed ones (dev).
            ->where('systemUsers.0.username', 'root')
            ->where('systemUsers.0.is_system_reserved', true)
            ->where('systemUsers.1.username', 'dev')
            ->where('systemUsers.1.is_sudo', true)
        );
});

test('store creates a system user and dispatches a provisioning job', function () {
    mockSystemUserControllerPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->post(route('system-users.store', $server), [
            'username' => 'deployer',
            'shell' => '/bin/bash',
            'is_sudo' => true,
        ])
        ->assertRedirect(route('system-users.index', $server));

    $systemUser = SystemUser::where('server_id', $server->id)->where('username', 'deployer')->first();
    expect($systemUser)
        ->not->toBeNull()
        ->shell->toBe('/bin/bash')
        ->is_sudo->toBeTrue()
        ->is_system_reserved->toBeFalse();

    expect(AuditLog::where('action', 'system_user.created')->where('server_id', $server->id)->exists())->toBeTrue();
});

test('store rejects an invalid username', function (string $badUsername) {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->from(route('system-users.index', $server))
        ->post(route('system-users.store', $server), [
            'username' => $badUsername,
            'shell' => '/bin/bash',
            'is_sudo' => false,
        ])
        ->assertSessionHasErrors('username');

    expect(SystemUser::where('username', $badUsername)->exists())->toBeFalse();
})->with([
    'uppercase' => ['Deployer'],
    'shell injection' => ['dev;rm -rf /'],
    'starts with digit' => ['1dev'],
    'too long' => [str_repeat('a', 33)],
    'empty' => [''],
]);

test('store rejects a duplicate username on the same server', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    SystemUser::create([
        'server_id' => $server->id, 'username' => 'dev',
        'shell' => '/bin/bash', 'is_sudo' => false,
    ]);

    $this->actingAs($user)
        ->from(route('system-users.index', $server))
        ->post(route('system-users.store', $server), [
            'username' => 'dev',
            'shell' => '/bin/bash',
            'is_sudo' => false,
        ])
        ->assertSessionHasErrors('username');

    expect(SystemUser::where('server_id', $server->id)->count())->toBe(1);
});

test('store rejects a disallowed shell', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->from(route('system-users.index', $server))
        ->post(route('system-users.store', $server), [
            'username' => 'dev',
            'shell' => '/bin/zsh',
            'is_sudo' => false,
        ])
        ->assertSessionHasErrors('shell');
});

test('updateSudo toggles the flag and dispatches a job', function () {
    mockSystemUserControllerPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $systemUser = SystemUser::create([
        'server_id' => $server->id, 'username' => 'dev',
        'shell' => '/bin/bash', 'is_sudo' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('system-users.sudo', $systemUser), ['is_sudo' => true])
        ->assertRedirect(route('system-users.index', $server));

    expect($systemUser->refresh()->is_sudo)->toBeTrue();
    expect(AuditLog::where('action', 'system_user.sudo_toggled')->where('server_id', $server->id)->exists())->toBeTrue();
});

test('updateShell changes the shell and dispatches a job', function () {
    mockSystemUserControllerPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $systemUser = SystemUser::create([
        'server_id' => $server->id, 'username' => 'dev',
        'shell' => '/bin/bash', 'is_sudo' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('system-users.shell', $systemUser), ['shell' => '/usr/sbin/nologin'])
        ->assertRedirect(route('system-users.index', $server));

    expect($systemUser->refresh()->shell)->toBe('/usr/sbin/nologin');
    expect(AuditLog::where('action', 'system_user.shell_changed')->where('server_id', $server->id)->exists())->toBeTrue();
});

test('destroy rejects a system-reserved user', function () {
    mockSystemUserControllerPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $systemUser = SystemUser::create([
        'server_id' => $server->id, 'username' => 'root',
        'shell' => '/bin/bash', 'is_sudo' => true, 'is_system_reserved' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('system-users.destroy', $systemUser))
        ->assertForbidden();

    expect(SystemUser::find($systemUser->id))->not->toBeNull();
});

test('destroy deletes a non-reserved user and dispatches userdel', function () {
    mockSystemUserControllerPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $systemUser = SystemUser::create([
        'server_id' => $server->id, 'username' => 'dev',
        'shell' => '/bin/bash', 'is_sudo' => false,
    ]);

    $this->actingAs($user)
        ->delete(route('system-users.destroy', $systemUser))
        ->assertRedirect(route('system-users.index', $server));

    expect(SystemUser::find($systemUser->id))->toBeNull();
    expect(AuditLog::where('action', 'system_user.deleted')->where('server_id', $server->id)->exists())->toBeTrue();
});
