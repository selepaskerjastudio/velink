<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Job stdout/stderr is streamed verbatim over the per-server channel, so an
 * unscoped subscription leaks live command output — including anything a
 * provisioning or deploy step happens to echo. This has to be gated the same
 * way the HTTP routes are, not merely "is authenticated".
 */
function authorizeServerChannel(Server $server): array
{
    return [
        'channel_name' => 'private-server.'.$server->uuid,
        // Real clients always send this; without it the broadcaster throws
        // while signing an *authorized* response, masking a 200 as a 500.
        'socket_id' => '1234.5678',
    ];
}

test('a member can subscribe to a server assigned to them', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();
    $member->servers()->attach($server);

    $this->actingAs($member)
        ->post('/broadcasting/auth', authorizeServerChannel($server))
        ->assertOk();
});

test('a member cannot subscribe to a server not assigned to them', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post('/broadcasting/auth', authorizeServerChannel($server))
        ->assertForbidden();
});

test('an admin can subscribe to any server', function () {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->post('/broadcasting/auth', authorizeServerChannel($server))
        ->assertOk();
});

test('a deactivated user cannot subscribe even to an assigned server', function () {
    $server = Server::factory()->create();
    $user = User::factory()->admin()->inactive()->create();
    $user->servers()->attach($server);

    $this->actingAs($user)
        ->post('/broadcasting/auth', authorizeServerChannel($server))
        ->assertForbidden();
});

test('subscribing to an unknown server uuid is refused', function () {
    $this->actingAs(User::factory()->member()->create())
        ->post('/broadcasting/auth', [
            'channel_name' => 'private-server.'.Str::uuid(),
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
