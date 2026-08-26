<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

test('the server list shows a member only their assigned servers', function () {
    $assigned = Server::factory()->create(['name' => 'assigned-box']);
    Server::factory()->create(['name' => 'other-box']);

    $member = User::factory()->member()->create();
    $member->servers()->attach($assigned);

    $this->actingAs($member)
        ->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page
            ->has('servers', 1)
            ->where('servers.0.name', 'assigned-box')
        );
});

test('the server list shows an admin every server', function () {
    Server::factory()->count(3)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('servers.index'))
        ->assertInertia(fn ($page) => $page->has('servers', 3));
});

test('dashboard counts only the servers a member can see', function () {
    $assigned = Server::factory()->create(['status' => 'online']);
    Server::factory()->count(2)->create(['status' => 'online']);

    $member = User::factory()->member()->create();
    $member->servers()->attach($assigned);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('serverCounts.total', 1)
            ->where('serverCounts.online', 1)
            ->has('servers', 1)
        );
});

test('audit log hides other servers activity from a member', function () {
    $assigned = Server::factory()->create();
    $other = Server::factory()->create();

    $member = User::factory()->member()->create();
    $member->servers()->attach($assigned);

    AuditLog::create([
        'user_id' => $member->id,
        'server_id' => $assigned->id,
        'action' => 'server.visible',
        'description' => 'on my server',
    ]);

    AuditLog::create([
        'user_id' => $member->id,
        'server_id' => $other->id,
        'action' => 'server.hidden',
        'description' => 'on someone elses server',
    ]);

    $this->actingAs($member)
        ->get(route('audit-logs.index'))
        ->assertInertia(fn ($page) => $page
            ->has('logs', 1)
            ->where('logs.0.action', 'server.visible')
        );
});

test('audit log still shows a member their own account-level actions', function () {
    $member = User::factory()->member()->create();

    // server_id is null for account-scoped actions. Filtering purely on
    // visible servers would wrongly hide these from the person who did them.
    AuditLog::create([
        'user_id' => $member->id,
        'server_id' => null,
        'action' => 'ssh_key.created',
        'description' => 'added a key',
    ]);

    AuditLog::create([
        'user_id' => User::factory()->admin()->create()->id,
        'server_id' => null,
        'action' => 'cloudflare.token_added',
        'description' => 'someone elses account action',
    ]);

    $this->actingAs($member)
        ->get(route('audit-logs.index'))
        ->assertInertia(fn ($page) => $page
            ->has('logs', 1)
            ->where('logs.0.action', 'ssh_key.created')
        );
});

test('an admin sees the whole audit log', function () {
    $server = Server::factory()->create();
    $admin = User::factory()->admin()->create();

    AuditLog::create(['user_id' => $admin->id, 'server_id' => $server->id, 'action' => 'a', 'description' => 'x']);
    AuditLog::create(['user_id' => $admin->id, 'server_id' => null, 'action' => 'b', 'description' => 'y']);

    $this->actingAs($admin)
        ->get(route('audit-logs.index'))
        ->assertInertia(fn ($page) => $page->has('logs', 2));
});

test('recent deployments on the dashboard are scoped to visible servers', function () {
    $assigned = Server::factory()->create();
    $other = Server::factory()->create();

    $member = User::factory()->member()->create();
    $member->servers()->attach($assigned);

    Deployment::factory()->for(Application::factory()->for($assigned))->create();
    Deployment::factory()->for(Application::factory()->for($other))->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->has('recentDeployments', 1));
});
