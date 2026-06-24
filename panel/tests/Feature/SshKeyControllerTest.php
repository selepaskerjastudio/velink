<?php

use App\Models\AuditLog;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

const VALID_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOUVskMQSrR6mA5dBMqTzhdi7ihbGagty1/+m/gV068b test@velink';
const VALID_FINGERPRINT = 'SHA256:z0WuM5b4c/V3G3yZAHIhS6/U4uWoXL6XGy18Jw+0+hU';

function mockSshControllerPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $this->get(route('ssh-keys.index'))->assertRedirect('/login');
    $this->post(route('ssh-keys.store'), [])->assertRedirect('/login');
});

test('the index page lists the current users keys with deployed servers', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $server = Server::factory()->online()->create();
    $admin = \App\Models\SystemUser::create([
        'server_id' => $server->id, 'username' => 'velink-admin',
        'shell' => '/bin/bash', 'is_sudo' => true,
    ]);
    $mine = SshKey::create([
        'user_id' => $user->id, 'name' => 'MacBook',
        'public_key' => VALID_KEY, 'fingerprint' => VALID_FINGERPRINT,
        'type' => 'ssh-ed25519', 'comment' => 'test@velink',
    ]);
    \DB::table('server_ssh_key')->insert([
        'server_id' => $server->id, 'ssh_key_id' => $mine->id,
        'system_user_id' => $admin->id, 'deployed_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A key owned by someone else must NOT appear.
    SshKey::create([
        'user_id' => $other->id, 'name' => 'Not mine',
        'public_key' => VALID_KEY, 'fingerprint' => VALID_FINGERPRINT,
        'type' => 'ssh-ed25519', 'comment' => 'other@host',
    ]);

    $this->actingAs($user)
        ->get(route('ssh-keys.index'))
        ->assertInertia(fn ($page) => $page
            ->has('sshKeys', 1)
            ->where('sshKeys.0.name', 'MacBook')
            ->where('sshKeys.0.fingerprint', VALID_FINGERPRINT)
            ->where('sshKeys.0.type', 'ssh-ed25519')
            ->has('sshKeys.0.servers', 1)
            ->where('sshKeys.0.servers.0.id', $server->uuid)
        );
});

test('an authenticated user can add an ssh key', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post(route('ssh-keys.store'), [
        'name' => 'MacBook Pro',
        'public_key' => VALID_KEY,
    ]);

    $response->assertRedirect(route('ssh-keys.index'));

    $key = SshKey::where('user_id', $user->id)->first();
    expect($key)
        ->name->toBe('MacBook Pro')
        ->public_key->toBe(VALID_KEY)
        ->fingerprint->toBe(VALID_FINGERPRINT)
        ->type->toBe('ssh-ed25519')
        ->comment->toBe('test@velink');

    expect(AuditLog::where('action', 'ssh_key.created')->exists())->toBeTrue();
});

test('store rejects an invalid public key format', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->from(route('ssh-keys.index'))
        ->post(route('ssh-keys.store'), ['name' => 'x', 'public_key' => 'not-a-key'])
        ->assertSessionHasErrors('public_key');

    expect(SshKey::count())->toBe(0);
});

test('store rejects a duplicate fingerprint for the same user', function () {
    $user = User::factory()->create();
    SshKey::create([
        'user_id' => $user->id, 'name' => 'first',
        'public_key' => VALID_KEY, 'fingerprint' => VALID_FINGERPRINT,
        'type' => 'ssh-ed25519', 'comment' => 'test@velink',
    ]);
    $this->actingAs($user);

    $this->from(route('ssh-keys.index'))
        ->post(route('ssh-keys.store'), ['name' => 'dup', 'public_key' => VALID_KEY])
        ->assertSessionHasErrors('public_key');

    expect(SshKey::where('user_id', $user->id)->count())->toBe(1);
});

test('the same key can be added by two different users', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $this->actingAs($a)->post(route('ssh-keys.store'), ['name' => 'a', 'public_key' => VALID_KEY]);

    auth()->logout();
    $this->actingAs($b)->post(route('ssh-keys.store'), ['name' => 'b', 'public_key' => VALID_KEY]);

    expect(SshKey::count())->toBe(2);
});

test('a user cannot delete an ssh key owned by someone else', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $key = SshKey::create([
        'user_id' => $owner->id, 'name' => 'owner',
        'public_key' => VALID_KEY, 'fingerprint' => VALID_FINGERPRINT,
        'type' => 'ssh-ed25519', 'comment' => 'owner@host',
    ]);

    $this->actingAs($intruder)
        ->delete(route('ssh-keys.destroy', $key))
        ->assertForbidden();

    expect(SshKey::where('id', $key->id)->exists())->toBeTrue();
});

test('destroy removes the key and undeploys it from every affected server', function () {
    mockSshControllerPublish();

    $user = User::factory()->create();
    $this->actingAs($user);

    $serverA = Server::factory()->online()->create();
    $serverB = Server::factory()->online()->create();
    $adminA = \App\Models\SystemUser::create(['server_id' => $serverA->id, 'username' => 'velink-admin', 'shell' => '/bin/bash']);
    $adminB = \App\Models\SystemUser::create(['server_id' => $serverB->id, 'username' => 'velink-admin', 'shell' => '/bin/bash']);
    $key = SshKey::create([
        'user_id' => $user->id, 'name' => 'x',
        'public_key' => VALID_KEY, 'fingerprint' => VALID_FINGERPRINT,
        'type' => 'ssh-ed25519', 'comment' => 'x@host',
    ]);
    \DB::table('server_ssh_key')->insert([
        ['server_id' => $serverA->id, 'ssh_key_id' => $key->id, 'system_user_id' => $adminA->id, 'deployed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['server_id' => $serverB->id, 'ssh_key_id' => $key->id, 'system_user_id' => $adminB->id, 'deployed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->delete(route('ssh-keys.destroy', $key))
        ->assertRedirect(route('ssh-keys.index'));

    // Key gone, pivot rows gone, and an authorized_keys rebuild was queued
    // for each server (2 writes detected via AgentJob rows).
    expect(SshKey::find($key->id))->toBeNull()
        ->and(\App\Models\AgentJob::where('server_id', $serverA->id)->where('type', 'write_file')->count())->toBeGreaterThanOrEqual(1)
        ->and(\App\Models\AgentJob::where('server_id', $serverB->id)->where('type', 'write_file')->count())->toBeGreaterThanOrEqual(1)
        ->and(AuditLog::where('action', 'ssh_key.deleted')->exists())->toBeTrue();
});
