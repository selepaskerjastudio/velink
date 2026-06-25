<?php

use App\Models\User;
use App\Models\Server;
use App\Models\SshKey;
use App\Services\SshKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

// A real ed25519 test key + its ssh-keygen fingerprint. Used to prove the
// pure-PHP fingerprint matches what OpenSSH itself produces.
const TEST_ED25519_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOUVskMQSrR6mA5dBMqTzhdi7ihbGagty1/+m/gV068b test@velink';
const TEST_ED25519_FINGERPRINT = 'SHA256:z0WuM5b4c/V3G3yZAHIhS6/U4uWoXL6XGy18Jw+0+hU';

test('parse_public_key extracts type and comment from an ed25519 key', function () {
    $parsed = app(SshKeyService::class)->parsePublicKey(TEST_ED25519_KEY);

    expect($parsed['type'])->toBe('ssh-ed25519')
        ->and($parsed['blob'])->toBe('AAAAC3NzaC1lZDI1NTE5AAAAIOUVskMQSrR6mA5dBMqTzhdi7ihbGagty1/+m/gV068b')
        ->and($parsed['comment'])->toBe('test@velink');
});

test('parse_public_key works without a trailing comment', function () {
    $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOUVskMQSrR6mA5dBMqTzhdi7ihbGagty1/+m/gV068b';
    $parsed = app(SshKeyService::class)->parsePublicKey($key);

    expect($parsed['type'])->toBe('ssh-ed25519')
        ->and($parsed['comment'])->toBeNull();
});

test('parse_public_key rejects malformed keys', function (string $badKey) {
    expect(fn () => app(SshKeyService::class)->parsePublicKey($badKey))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'garbage' => ['not-a-key'],
    'missing base64 blob' => ['ssh-ed25519'],
    'invalid base64' => ['ssh-ed25519 !!!not-base64!!!'],
    'unknown key type' => ['ssh-bogus AAAAC3NzaC1lZDI1NTE5AAAAIOUVskMQSrR6mA5dBMqTzhdi7ihbGagty1/+m/gV068b'],
    'rsa blob whose embedded type does not match prefix' => ['ssh-ed25519 AAAAB3NzaC1yc2EAAAADAQABAAAgQC1XKr3ogaC+test'],
]);

test('compute_fingerprint matches the ssh-keygen output', function () {
    $fingerprint = app(SshKeyService::class)->computeFingerprint(TEST_ED25519_KEY);

    expect($fingerprint)->toBe(TEST_ED25519_FINGERPRINT);
});

test('compute_fingerprint is deterministic', function () {
    $service = app(SshKeyService::class);

    expect($service->computeFingerprint(TEST_ED25519_KEY))
        ->toBe($service->computeFingerprint(TEST_ED25519_KEY));
});

test('compute_fingerprint differs across keys', function () {
    $service = app(SshKeyService::class);
    $otherKey = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDifferentBlobHereWouldProduceADifferentHash test2@velink';

    expect($service->computeFingerprint(TEST_ED25519_KEY))
        ->not->toBe($service->computeFingerprint($otherKey));
});

// Capture helper for the deployment tests below.
function mockSshGatewayPublish(?array &$published = null): void
{
    $published = &$published;
    $captured = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$captured) {
        $captured[] = json_decode($json, true);
        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    // Expose captured via a reference returned by the helper's caller using a closure.
    // Simpler: stash on a global since these tests are isolated.
    $GLOBALS['__ssh_captured'] = &$captured;
}

function sshCaptured(): array
{
    return $GLOBALS['__ssh_captured'] ?? [];
}

test('deploy_to_server syncs authorized_keys via a useradd + write_file + chmod job sequence', function () {
    mockSshGatewayPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $targetUser = \App\Models\SystemUser::create([
        'server_id' => $server->id, 'username' => 'deployer',
        'shell' => '/bin/bash', 'is_sudo' => true,
    ]);
    $key = SshKey::create([
        'user_id' => $user->id,
        'name' => 'MacBook',
        'public_key' => TEST_ED25519_KEY,
        'fingerprint' => TEST_ED25519_FINGERPRINT,
        'type' => 'ssh-ed25519',
        'comment' => 'test@velink',
    ]);

    app(SshKeyService::class)->deployToServer($key, $server, $targetUser, $user->id);

    // Pivot row recorded against the target user.
    $deployed = \DB::table('server_ssh_key')
        ->where('server_id', $server->id)
        ->where('ssh_key_id', $key->id)
        ->where('system_user_id', $targetUser->id)
        ->exists();
    expect($deployed)->toBeTrue();

    $captured = sshCaptured();
    expect($captured)->not->toBeEmpty();

    // Reconstruct the dispatched jobs from the captured envelopes.
    $jobs = collect($captured)->pluck('payload')->all();
    $actions = array_map(fn ($p) => $p['action'], $jobs);
    expect($actions)->toContain('shell');
    expect($actions)->toContain('write_file');

    // The useradd shell job references the target user idempotently.
    $useraddJob = collect($jobs)->first(fn ($p) => $p['action'] === 'shell' && str_contains($p['params']['command'] ?? '', 'useradd'));
    expect($useraddJob['params']['command'])->toContain('deployer');

    // The authorized_keys write_file lands under the target user's home at 0600.
    $writeJob = collect($jobs)->first(fn ($p) => $p['action'] === 'write_file');
    expect($writeJob['params']['path'])->toBe('/home/deployer/.ssh/authorized_keys');
    expect($writeJob['params']['mode'])->toBe('0600');
    expect($writeJob['params']['content'])->toContain(TEST_ED25519_KEY);
});

test('deploy_to_server to different users writes separate authorized_keys files', function () {
    mockSshGatewayPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $deployer = \App\Models\SystemUser::create(['server_id' => $server->id, 'username' => 'deployer', 'shell' => '/bin/bash']);
    $ci = \App\Models\SystemUser::create(['server_id' => $server->id, 'username' => 'ci', 'shell' => '/bin/bash']);
    $key = SshKey::create([
        'user_id' => $user->id, 'name' => 'MacBook',
        'public_key' => TEST_ED25519_KEY, 'fingerprint' => TEST_ED25519_FINGERPRINT,
        'type' => 'ssh-ed25519', 'comment' => 'test@velink',
    ]);

    $service = app(SshKeyService::class);
    $service->deployToServer($key, $server, $deployer, $user->id);
    $service->deployToServer($key, $server, $ci, $user->id);

    $captured = sshCaptured();
    $paths = collect($captured)->pluck('payload')
        ->filter(fn ($p) => $p['action'] === 'write_file')
        ->pluck('params.path')->unique();
    expect($paths)->toContain('/home/deployer/.ssh/authorized_keys')
        ->and($paths)->toContain('/home/ci/.ssh/authorized_keys');
});

test('sync_server_keys rewrites authorized_keys with the full deployed set', function () {
    mockSshGatewayPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $targetUser = \App\Models\SystemUser::create(['server_id' => $server->id, 'username' => 'deployer', 'shell' => '/bin/bash']);

    $keyA = SshKey::create([
        'user_id' => $user->id, 'name' => 'A',
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOUVskMQSrR6mA5dBMqTzhdi7ihbGagty1/+m/gV068b a@velink',
        'fingerprint' => 'SHA256:aaa', 'type' => 'ssh-ed25519', 'comment' => 'a@velink',
    ]);
    $keyB = SshKey::create([
        'user_id' => $user->id, 'name' => 'B',
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGifferentBlobGifferentDifferentDifferent aaaa b@velink',
        'fingerprint' => 'SHA256:bbb', 'type' => 'ssh-ed25519', 'comment' => 'b@velink',
    ]);

    // Both keys deployed to the same target user.
    \DB::table('server_ssh_key')->insert([
        ['server_id' => $server->id, 'ssh_key_id' => $keyA->id, 'system_user_id' => $targetUser->id, 'deployed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['server_id' => $server->id, 'ssh_key_id' => $keyB->id, 'system_user_id' => $targetUser->id, 'deployed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(SshKeyService::class)->syncServerKeys($server, $user->id);

    $captured = sshCaptured();
    $writeJob = collect($captured)->pluck('payload')->first(fn ($p) => $p['action'] === 'write_file');
    expect($writeJob['params']['content'])->toContain('a@velink')
        ->and($writeJob['params']['content'])->toContain('b@velink');
});

test('revoke from a user rebuilds only that user authorized_keys', function () {
    mockSshGatewayPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $deployer = \App\Models\SystemUser::create(['server_id' => $server->id, 'username' => 'deployer', 'shell' => '/bin/bash']);
    $key = SshKey::create([
        'user_id' => $user->id, 'name' => 'MacBook',
        'public_key' => TEST_ED25519_KEY, 'fingerprint' => TEST_ED25519_FINGERPRINT,
        'type' => 'ssh-ed25519', 'comment' => 'test@velink',
    ]);

    $service = app(SshKeyService::class);
    $service->deployToServer($key, $server, $deployer, $user->id);
    $service->revokeFromUser($key, $server, $deployer, $user->id);

    $captured = sshCaptured();
    // The last write_file after revoke contains no key.
    $writeJobs = collect($captured)->pluck('payload')->filter(fn ($p) => $p['action'] === 'write_file');
    expect(trim($writeJobs->last()['params']['content']))->toBe('');
    // And the pivot row is gone.
    expect(\DB::table('server_ssh_key')->where('system_user_id', $deployer->id)->exists())->toBeFalse();
});

test('ensure_default_admin materialises a velink-admin system user', function () {
    $server = Server::factory()->online()->create();
    $service = app(SshKeyService::class);

    $admin = $service->ensureDefaultAdmin($server);
    expect($admin->username)->toBe('velink-admin')
        ->and($admin->is_sudo)->toBeTrue()
        ->and($admin->is_system_reserved)->toBeTrue();

    // Idempotent — returns the same row, doesn't create a duplicate.
    expect($service->ensureDefaultAdmin($server)->id)->toBe($admin->id);
    expect(\App\Models\SystemUser::where('server_id', $server->id)->count())->toBe(1);
});

