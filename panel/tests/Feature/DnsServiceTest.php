<?php

use App\Models\Application;
use App\Models\CloudflareToken;
use App\Models\DnsRecord;
use App\Models\Server;
use App\Models\User;
use App\Services\DnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CF_API = 'https://api.cloudflare.com/client/v4';

function makeAppWithServer(): array
{
    $user = User::factory()->create();
    $server = Server::factory()->online()->create(['public_ip' => '157.245.150.2']);
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
    ]);
    $token = CloudflareToken::create([
        'user_id' => $user->id,
        'email' => 'admin@example.com',
        'api_token' => 'cf-secret',
        'verified_at' => now(),
    ]);

    return [$app, $server, $user, $token];
}

test('provision_domain creates an A record pointing to the server public IP', function () {
    [$app, $server, $user, $token] = makeAppWithServer();

    Http::fake([
        CF_API.'/zones?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-aaa', 'name' => 'example.com']],
        ]),
        CF_API.'/zones' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-aaa', 'name' => 'example.com']],
        ]),
        CF_API.'/zones/zone-aaa/dns_records' => Http::response([
            'success' => true,
            'result' => ['id' => 'rec-abc', 'name' => 'myapp.example.com'],
        ]),
    ]);

    app(DnsService::class)->provisionDomain($app, $token);

    $dnsRecord = $app->dnsRecords()->first();
    expect($dnsRecord)->not->toBeNull()
        ->and($dnsRecord->type)->toBe('A')
        ->and($dnsRecord->name)->toBe('myapp.example.com')
        ->and($dnsRecord->content)->toBe('157.245.150.2')
        ->and($dnsRecord->record_id)->toBe('rec-abc')
        ->and($dnsRecord->zone_id)->toBe('zone-aaa')
        ->and($dnsRecord->cloudflare_token_id)->toBe($token->id);
});

test('provision_domain is non-blocking on CF failure', function () {
    [$app, $server, $user, $token] = makeAppWithServer();

    // Cloudflare returns an error — provisionDomain should NOT throw.
    Http::fake([
        CF_API.'/zones*' => Http::response(['success' => false, 'errors' => [['message' => 'Forbidden']], 'result' => []], 403),
    ]);

    app(DnsService::class)->provisionDomain($app, $token);

    // No DNS record row was created, but no exception was thrown either.
    expect($app->dnsRecords()->count())->toBe(0);
});

test('provision_domain skips when the domain has no matching zone', function () {
    [$app, $server, $user, $token] = makeAppWithServer();

    Http::fake([
        CF_API.'/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-other', 'name' => 'different.com']],
        ]),
    ]);

    app(DnsService::class)->provisionDomain($app, $token);

    expect($app->dnsRecords()->count())->toBe(0);
});

test('teardown_domain deletes CF records and removes DB rows', function () {
    [$app, , , $token] = makeAppWithServer();

    DnsRecord::create([
        'application_id' => $app->id,
        'cloudflare_token_id' => $token->id,
        'zone_id' => 'zone-aaa',
        'record_id' => 'rec-abc',
        'type' => 'A',
        'name' => 'myapp.example.com',
        'content' => '157.245.150.2',
        'proxied' => false,
        'ttl' => 1,
    ]);

    Http::fake([
        CF_API.'/zones/zone-aaa/dns_records/rec-abc' => Http::response(['success' => true]),
    ]);

    app(DnsService::class)->teardownDomain($app, $token);

    expect(DnsRecord::where('application_id', $app->id)->count())->toBe(0);
});

test('teardown_domain is non-blocking when CF delete fails', function () {
    [$app, , , $token] = makeAppWithServer();

    DnsRecord::create([
        'application_id' => $app->id,
        'cloudflare_token_id' => $token->id,
        'zone_id' => 'zone-aaa',
        'record_id' => 'rec-abc',
        'type' => 'A',
        'name' => 'myapp.example.com',
        'content' => '157.245.150.2',
        'proxied' => false,
        'ttl' => 1,
    ]);

    // CF returns error — teardown should still clean up the DB row.
    Http::fake([
        CF_API.'/zones/zone-aaa/dns_records/rec-abc' => Http::response(['success' => false], 500),
    ]);

    app(DnsService::class)->teardownDomain($app, $token);

    expect(DnsRecord::where('application_id', $app->id)->count())->toBe(0);
});
