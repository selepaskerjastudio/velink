<?php

use App\Services\CloudflareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CF_API = 'https://api.cloudflare.com/client/v4';
const VALID_TOKEN = 'cf-test-token-12345';

function fakeCfVerify(): void
{
    Http::fake([
        CF_API.'/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['id' => 'tok-1', 'status' => 'active'],
        ], 200),
    ]);
}

test('verify_token returns true for a valid token', function () {
    fakeCfVerify();

    $result = app(CloudflareService::class)->verifyToken(VALID_TOKEN);

    expect($result['valid'])->toBeTrue();
});

test('verify_token returns false for an invalid token', function () {
    Http::fake([
        CF_API.'/user/tokens/verify' => Http::response([
            'success' => false,
            'errors' => [['message' => 'Invalid token']],
        ], 401),
    ]);

    $result = app(CloudflareService::class)->verifyToken('bad-token');

    expect($result['valid'])->toBeFalse();
});

test('list_zones returns zone id and name pairs', function () {
    Http::fake([
        CF_API.'/zones*' => Http::response([
            'success' => true,
            'result' => [
                ['id' => 'zone-aaa', 'name' => 'example.com'],
                ['id' => 'zone-bbb', 'name' => 'other.org'],
            ],
        ], 200),
    ]);

    $zones = app(CloudflareService::class)->listZones(VALID_TOKEN);

    expect($zones)->toHaveCount(2)
        ->and($zones[0]['id'])->toBe('zone-aaa')
        ->and($zones[0]['name'])->toBe('example.com');
});

test('find_zone_for_domain resolves the correct zone from a subdomain', function () {
    Http::fake([
        CF_API.'/zones*' => Http::response([
            'success' => true,
            'result' => [
                ['id' => 'zone-aaa', 'name' => 'example.com'],
                ['id' => 'zone-bbb', 'name' => 'other.org'],
            ],
        ], 200),
    ]);

    $zoneId = app(CloudflareService::class)->findZoneForDomain(VALID_TOKEN, 'app.sub.example.com');

    expect($zoneId)->toBe('zone-aaa');
});

test('find_zone_for_domain returns null when no zone matches', function () {
    Http::fake([
        CF_API.'/zones*' => Http::response(['success' => true, 'result' => []], 200),
    ]);

    $zoneId = app(CloudflareService::class)->findZoneForDomain(VALID_TOKEN, 'nowhere.com');

    expect($zoneId)->toBeNull();
});

test('create_record posts to the correct zone endpoint and returns the record id', function () {
    Http::fake([
        CF_API.'/zones/zone-aaa/dns_records' => Http::response([
            'success' => true,
            'result' => ['id' => 'rec-123', 'name' => 'app.example.com'],
        ], 200),
    ]);

    $recordId = app(CloudflareService::class)->createRecord(VALID_TOKEN, 'zone-aaa', [
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '157.245.150.2',
        'proxied' => false,
        'ttl' => 1,
    ]);

    expect($recordId)->toBe('rec-123');

    Http::assertSent(function ($request) {
        return $request->url() === CF_API.'/zones/zone-aaa/dns_records'
            && $request['type'] === 'A'
            && $request['content'] === '157.245.150.2';
    });
});

test('delete_record deletes by record id', function () {
    Http::fake([
        CF_API.'/zones/zone-aaa/dns_records/rec-123' => Http::response([
            'success' => true,
            'result' => ['id' => 'rec-123'],
        ], 200),
    ]);

    $ok = app(CloudflareService::class)->deleteRecord(VALID_TOKEN, 'zone-aaa', 'rec-123');

    expect($ok)->toBeTrue();
});

test('list_records returns records for a zone', function () {
    Http::fake([
        CF_API.'/zones/zone-aaa/dns_records*' => Http::response([
            'success' => true,
            'result' => [
                ['id' => 'rec-1', 'type' => 'A', 'name' => 'example.com', 'content' => '1.2.3.4'],
                ['id' => 'rec-2', 'type' => 'CNAME', 'name' => 'www', 'content' => 'example.com'],
            ],
        ], 200),
    ]);

    $records = app(CloudflareService::class)->listRecords(VALID_TOKEN, 'zone-aaa');

    expect($records)->toHaveCount(2)
        ->and($records[0]['id'])->toBe('rec-1');
});
