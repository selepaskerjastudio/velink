<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// These assert authorization, not rendering. Stubbing Vite keeps them honest
// about a 200-vs-403 distinction instead of failing on whatever the local
// asset build happens to contain.
beforeEach(fn () => $this->withoutVite());

/**
 * The positive direction here is the important one. If EnforceServerScope ran
 * before SubstituteBindings, route parameters would still be raw strings, the
 * middleware would resolve no server, and every server route would fall to the
 * deny branch — locking members out of everything while admins (who return
 * early) noticed nothing. A suite that only asserted 403s would pass happily
 * in that state.
 */
test('a member can reach a server assigned to them', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();
    $member->servers()->attach($server);

    $this->actingAs($member)
        ->get(route('servers.show', $server))
        ->assertOk();
});

test('a member is denied a server not assigned to them', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('servers.show', $server))
        ->assertForbidden();
});

test('an admin can reach a server nobody is assigned to', function () {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('servers.show', $server))
        ->assertOk();
});

test('scoping follows a nested route to the owning server', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();
    $member->servers()->attach($server);

    $this->actingAs($member)
        ->get(route('applications.server-index', $server))
        ->assertOk();

    $this->actingAs($member)
        ->get(route('applications.server-index', Server::factory()->create()))
        ->assertForbidden();
});

test('scoping resolves through a BelongsToServer model, not just {server}', function () {
    $assigned = Server::factory()->create();
    $other = Server::factory()->create();

    $ownApp = Application::factory()->for($assigned)->create();
    $otherApp = Application::factory()->for($other)->create();

    $member = User::factory()->member()->create();
    $member->servers()->attach($assigned);

    $this->actingAs($member)
        ->get(route('applications.show', $ownApp))
        ->assertOk();

    // {application} carries no server segment in the URL — the middleware has
    // to walk Application->server to know which server this touches.
    $this->actingAs($member)
        ->get(route('applications.show', $otherApp))
        ->assertForbidden();
});

test('a member is denied admin-only actions on a server they are assigned to', function () {
    $server = Server::factory()->create();
    $member = User::factory()->member()->create();
    $member->servers()->attach($server);

    $this->actingAs($member)->get(route('servers.terminal', $server))->assertForbidden();
    $this->actingAs($member)->get(route('security.index', $server))->assertForbidden();
    $this->actingAs($member)->get(route('system-users.index', $server))->assertForbidden();
    $this->actingAs($member)->post(route('servers.restart', $server))->assertForbidden();
    $this->actingAs($member)->post(route('servers.regenerate-token', $server))->assertForbidden();
    $this->actingAs($member)->delete(route('servers.destroy', $server))->assertForbidden();
    $this->actingAs($member)->get(route('servers.create'))->assertForbidden();
});

test('an admin can reach the admin-only actions', function () {
    $server = Server::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('servers.create'))
        ->assertOk();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('security.index', $server))
        ->assertOk();
});

test('account settings stay reachable for a member with no servers', function () {
    $member = User::factory()->member()->create();

    $this->actingAs($member)->get(route('profile.edit'))->assertOk();
    $this->actingAs($member)->get(route('ssh-keys.index'))->assertOk();
    $this->actingAs($member)->get(route('servers.index'))->assertOk();
    $this->actingAs($member)->get(route('dashboard'))->assertOk();
});
