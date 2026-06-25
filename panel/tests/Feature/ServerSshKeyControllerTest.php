<?php

use App\Models\AgentJob;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

const TEST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOUVskMQSrR6mA5dBMqTzhdi7ihbGagty1/+m/gV068b test@velink';

function mockDeployPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

function sshKeyFixture(int $userId): SshKey
{
    return SshKey::create([
        'user_id' => $userId,
        'name' => 'MacBook',
        'public_key' => TEST_KEY,
        'fingerprint' => 'SHA256:z0WuM5b4c/V3G3yZAHIhS6/U4uWoXL6XGy18Jw+0+hU',
        'type' => 'ssh-ed25519',
        'comment' => 'test@velink',
    ]);
}

test('guests cannot deploy or revoke ssh keys', function () {
    $server = Server::factory()->create();
    $user = User::factory()->create();
    $key = sshKeyFixture($user->id);

    $this->post(route('server.ssh-keys.deploy', [$server, $key]))->assertRedirect('/login');
    $this->delete(route('server.ssh-keys.revoke', [$server, $key]))->assertRedirect('/login');
});

test('deploy without a target user defaults to the velink-admin account', function () {
    mockDeployPublish();

    $user = User::factory()->create();
    $this->actingAs($user);
    $server = Server::factory()->online()->create();
    $key = sshKeyFixture($user->id);

    $this->post(route('server.ssh-keys.deploy', [$server, $key]))
        ->assertRedirect(route('servers.ssh-keys', $server));

    // A velink-admin system user was materialised and the key is attached to it.
    $admin = \App\Models\SystemUser::where('server_id', $server->id)->where('username', 'velink-admin')->first();
    expect($admin)->not->toBeNull();
    $deployed = \DB::table('server_ssh_key')
        ->where('server_id', $server->id)
        ->where('ssh_key_id', $key->id)
        ->where('system_user_id', $admin->id)
        ->exists();
    expect($deployed)->toBeTrue();

    // The full sync sequence (useradd + write_file + chmod) ran against velink-admin.
    $writeJobs = AgentJob::where('server_id', $server->id)->where('type', 'write_file')->get();
    expect($writeJobs)->not->toBeEmpty();
    expect($writeJobs->last()->payload['path'])->toBe('/home/velink-admin/.ssh/authorized_keys');
    expect($writeJobs->last()->payload['content'])->toContain(TEST_KEY);

    expect(AuditLog::where('action', 'ssh_key.deployed')->where('server_id', $server->id)->exists())->toBeTrue();
});

test('deploy to a specific system user targets that users authorized_keys', function () {
    mockDeployPublish();

    $user = User::factory()->create();
    $this->actingAs($user);
    $server = Server::factory()->online()->create();
    $deployer = \App\Models\SystemUser::create([
        'server_id' => $server->id, 'username' => 'deployer', 'shell' => '/bin/bash',
    ]);
    $key = sshKeyFixture($user->id);

    $this->post(route('server.ssh-keys.deploy', [$server, $key]), ['system_user_id' => $deployer->uuid])
        ->assertRedirect(route('servers.ssh-keys', $server));

    $writeJobs = AgentJob::where('server_id', $server->id)->where('type', 'write_file')->get();
    expect($writeJobs->last()->payload['path'])->toBe('/home/deployer/.ssh/authorized_keys');
});

test('deploy is idempotent — re-deploying the same key does not duplicate the pivot', function () {
    mockDeployPublish();

    $user = User::factory()->create();
    $this->actingAs($user);
    $server = Server::factory()->online()->create();
    $key = sshKeyFixture($user->id);

    $this->post(route('server.ssh-keys.deploy', [$server, $key]));
    $this->post(route('server.ssh-keys.deploy', [$server, $key]));

    expect($server->sshKeys()->where('ssh_key_id', $key->id)->count())->toBe(1);
});

test('deploy rejects a key owned by another user', function () {
    mockDeployPublish();

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $this->actingAs($intruder);
    $server = Server::factory()->online()->create();
    $key = sshKeyFixture($owner->id);

    $this->post(route('server.ssh-keys.deploy', [$server, $key]))->assertForbidden();
    expect($server->sshKeys()->count())->toBe(0);
});

test('revoke detaches the pivot and rebuilds authorized_keys without the key', function () {
    mockDeployPublish();

    $user = User::factory()->create();
    $this->actingAs($user);
    $server = Server::factory()->online()->create();
    $key = sshKeyFixture($user->id);
    // Deploy first (materialises the default velink-admin user + pivot row).
    $this->post(route('server.ssh-keys.deploy', [$server, $key]));

    $this->delete(route('server.ssh-keys.revoke', [$server, $key]))
        ->assertRedirect(route('servers.ssh-keys', $server));

    expect($server->sshKeys()->where('ssh_key_id', $key->id)->exists())->toBeFalse();

    // The rebuild after revoke writes an authorized_keys with no key content.
    $lastWrite = AgentJob::where('server_id', $server->id)
        ->where('type', 'write_file')
        ->latest('id')->first();
    expect($lastWrite)->not->toBeNull()
        ->and(trim($lastWrite->payload['content']))->toBe('');

    expect(AuditLog::where('action', 'ssh_key.revoked')->where('server_id', $server->id)->exists())->toBeTrue();
});
