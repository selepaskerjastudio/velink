<?php

use App\Models\Application;
use App\Models\CloudflareToken;
use App\Models\DnsRecord;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CF_API = 'https://api.cloudflare.com/client/v4';

function makeDnsApp(): array
{
    $user = User::factory()->create();
    $server = Server::factory()->online()->create(['public_ip' => '1.2.3.4']);
    $app = Application::factory()->create(['server_id' => $server->id, 'domain' => 'app.example.com']);
    $token = CloudflareToken::create([
        'user_id' => $user->id, 'email' => 'a@b.com', 'api_token' => 'tok', 'verified_at' => now(),
    ]);

    return [$user, $app, $server, $token];
}

test('guests are redirected to the login page', function () {
    [$user, $app] = makeDnsApp();

    $this->get(route('dns.index', $app))->assertRedirect('/login');
});

test('the dns page lists records for the application', function () {
    [$user, $app] = makeDnsApp();
    DnsRecord::create([
        'application_id' => $app->id, 'cloudflare_token_id' => $user->cloudflareTokens()->first()->id,
        'zone_id' => 'z1', 'record_id' => 'r1', 'type' => 'A', 'name' => 'app.example.com',
        'content' => '1.2.3.4', 'proxied' => false, 'ttl' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('dns.index', $app))
        ->assertInertia(fn ($page) => $page
            ->component('apps/dns')
            ->has('dnsRecords', 1)
            ->where('dnsRecords.0.type', 'A')
            ->where('dnsRecords.0.name', 'app.example.com')
            ->where('hasCloudflareToken', true)
        );
});

test('create dns record calls the Cloudflare API and stores a row', function () {
    [$user, $app] = makeDnsApp();

    Http::fake([
        CF_API.'/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'z1', 'name' => 'example.com']]]),
        CF_API.'/zones' => Http::response(['success' => true, 'result' => [['id' => 'z1', 'name' => 'example.com']]]),
        CF_API.'/zones/z1/dns_records' => Http::response(['success' => true, 'result' => ['id' => 'r2']]),
    ]);

    $this->actingAs($user)
        ->post(route('dns.store', $app), [
            'type' => 'CNAME', 'name' => 'www.example.com', 'content' => 'app.example.com', 'proxied' => true,
        ])
        ->assertRedirect(route('dns.index', $app));

    expect($app->dnsRecords()->where('type', 'CNAME')->exists())->toBeTrue();
});

test('create dns record rejects when no cloudflare token is connected', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create(['server_id' => $server->id, 'domain' => 'test.com']);

    $this->actingAs($user)
        ->from(route('dns.index', $app))
        ->post(route('dns.store', $app), [
            'type' => 'A', 'name' => 'test.com', 'content' => '1.2.3.4',
        ])
        ->assertSessionHasErrors('type');
});

test('delete dns record calls the Cloudflare API and removes the row', function () {
    [$user, $app] = makeDnsApp();
    $record = DnsRecord::create([
        'application_id' => $app->id, 'cloudflare_token_id' => $user->cloudflareTokens()->first()->id,
        'zone_id' => 'z1', 'record_id' => 'r1', 'type' => 'A', 'name' => 'app.example.com',
        'content' => '1.2.3.4', 'proxied' => false, 'ttl' => 1,
    ]);

    Http::fake([
        CF_API.'/zones/z1/dns_records/r1' => Http::response(['success' => true]),
    ]);

    $this->actingAs($user)
        ->delete(route('dns.destroy', [$app, $record]))
        ->assertRedirect(route('dns.index', $app));

    expect(DnsRecord::find($record->id))->toBeNull();
});
