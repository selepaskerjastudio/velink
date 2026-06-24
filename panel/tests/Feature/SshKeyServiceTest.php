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
    $key = SshKey::create([
        'user_id' => $user->id,
        'name' => 'MacBook',
        'public_key' => TEST_ED25519_KEY,
        'fingerprint' => TEST_ED25519_FINGERPRINT,
        'type' => 'ssh-ed25519',
        'comment' => 'test@velink',
    ]);

    app(SshKeyService::class)->deployToServer($key, $server, $user->id);

    // Pivot row recorded.
    expect($server->sshKeys()->where('ssh_key_id', $key->id)->exists())->toBeTrue();

    $captured = sshCaptured();
    expect($captured)->not->toBeEmpty();

    // Reconstruct the dispatched jobs from the captured envelopes.
    $jobs = collect($captured)->pluck('payload')->all();
    $actions = array_map(fn ($p) => $p['action'], $jobs);
    expect($actions)->toContain('shell');
    expect($actions)->toContain('write_file');

    // The useradd shell job references the dedicated admin user idempotently.
    $useraddJob = collect($jobs)->first(fn ($p) => $p['action'] === 'shell' && str_contains($p['params']['command'] ?? '', 'useradd'));
    expect($useraddJob['params']['command'])->toContain('velink-admin');

    // The authorized_keys write_file lands under the admin user's home at 0600.
    $writeJob = collect($jobs)->first(fn ($p) => $p['action'] === 'write_file');
    expect($writeJob['params']['path'])->toBe('/home/velink-admin/.ssh/authorized_keys');
    expect($writeJob['params']['mode'])->toBe('0600');
    expect($writeJob['params']['content'])->toContain(TEST_ED25519_KEY);
});

test('sync_server_keys rewrites authorized_keys with the full deployed set', function () {
    mockSshGatewayPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

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

    // Both keys deployed.
    $server->sshKeys()->attach([$keyA->id => ['deployed_at' => now()], $keyB->id => ['deployed_at' => now()]]);

    app(SshKeyService::class)->syncServerKeys($server, $user->id);

    $captured = sshCaptured();
    $writeJob = collect($captured)->pluck('payload')->first(fn ($p) => $p['action'] === 'write_file');
    expect($writeJob['params']['content'])->toContain('a@velink')
        ->and($writeJob['params']['content'])->toContain('b@velink');
});

test('sync_server_keys writes an empty authorized_keys when no keys remain', function () {
    mockSshGatewayPublish();

    $server = Server::factory()->online()->create();

    app(SshKeyService::class)->syncServerKeys($server, null);

    $captured = sshCaptured();
    $writeJob = collect($captured)->pluck('payload')->first(fn ($p) => $p['action'] === 'write_file');
    // Empty file — not a missing file — so revoking the last key locks the user out cleanly.
    expect(trim($writeJob['params']['content']))->toBe('');
});
