<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// User::canAccessServer()

test('an admin can access any server, assigned or not', function () {
    $admin = User::factory()->admin()->create();
    $server = Server::factory()->create();

    expect($admin->canAccessServer($server))->toBeTrue();
});

test('a member can access a server they are assigned to', function () {
    $member = User::factory()->member()->create();
    $server = Server::factory()->create();
    $member->servers()->attach($server);

    expect($member->canAccessServer($server))->toBeTrue();
});

test('a member cannot access a server they are not assigned to', function () {
    $member = User::factory()->member()->create();
    $server = Server::factory()->create();

    expect($member->canAccessServer($server))->toBeFalse();
});

test('a member assigned to one server cannot access a different server', function () {
    $member = User::factory()->member()->create();
    $assigned = Server::factory()->create();
    $other = Server::factory()->create();
    $member->servers()->attach($assigned);

    expect($member->canAccessServer($other))->toBeFalse();
});

// Server::scopeVisibleTo()

test('visibleTo returns every server for an admin, including unassigned ones', function () {
    $admin = User::factory()->admin()->create();
    Server::factory()->count(3)->create();

    expect(Server::visibleTo($admin)->count())->toBe(3);
});

test('visibleTo returns only assigned servers for a member', function () {
    $member = User::factory()->member()->create();
    $assigned = Server::factory()->count(2)->create();
    Server::factory()->count(2)->create(); // unassigned, must not appear

    $member->servers()->attach($assigned);

    $visible = Server::visibleTo($member)->pluck('id')->sort()->values();

    expect($visible->all())->toBe($assigned->pluck('id')->sort()->values()->all());
});

test('visibleTo returns nothing for a member assigned to no servers', function () {
    $member = User::factory()->member()->create();
    Server::factory()->count(3)->create();

    expect(Server::visibleTo($member)->count())->toBe(0);
});
