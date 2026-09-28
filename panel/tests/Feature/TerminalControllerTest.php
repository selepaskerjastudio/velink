<?php

use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\AppTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.gateway.secret' => 'test-gateway-secret']);
});

test('guests cannot access the terminal page', function () {
    $server = Server::factory()->create();

    $this->get(route('servers.terminal', $server))->assertRedirect('/login');
});

test('the terminal page generates a one-time session token', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    // The token is stored in cache — verify it exists after loading the page.
    $this->actingAs($user)
        ->get(route('servers.terminal', $server))
        ->assertInertia(fn ($page) => $page
            ->component('servers/terminal')
            ->has('terminalToken')
            ->has('gatewayUrl')
            ->has('systemUsers')
            ->where('terminalToken', fn ($token) => filled($token) && Cache::get("terminal:session:{$token}") !== null)
        );

    // Opening a terminal is a root-shell grant — TerminalController used to
    // import AuditLogger without ever calling it, leaving the most
    // security-relevant action in the app with no audit trail at all.
    expect(AuditLog::where('action', 'terminal.session_opened')
        ->where('user_id', $user->id)
        ->where('server_id', $server->id)
        ->exists())->toBeTrue();
});

test('reconnecting (JSON request) is logged distinctly from the initial page load', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)->getJson(route('servers.terminal', $server))->assertOk();

    $log = AuditLog::where('action', 'terminal.session_opened')->where('user_id', $user->id)->firstOrFail();
    expect($log->properties['reconnect'])->toBeTrue();
});

test('terminal auth validates a valid admin session token', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $token = fake()->uuid();

    Cache::put("terminal:session:{$token}", [
        'server_uuid' => $server->uuid,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'is_admin' => true,
    ], 60);

    $response = $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $server->uuid,
            'session_token' => $token,
            'user' => 'root',
        ]);

    $response->assertOk()
        ->assertJson(['valid' => true]);

    // Token is single-use — deleted after auth.
    expect(Cache::get("terminal:session:{$token}"))->toBeNull();

    // The gateway's confirmation that a PTY is about to open is logged too,
    // attributed to the user who requested it (carried in the cached session).
    expect(AuditLog::where('action', 'terminal.session_verified')
        ->where('user_id', $user->id)
        ->where('server_id', $server->id)
        ->exists())->toBeTrue();
});

test('terminal auth rejects an expired or invalid token', function () {
    $server = Server::factory()->online()->create();

    $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $server->uuid,
            'session_token' => 'nonexistent-token',
        ])
        ->assertStatus(401);
});

test('terminal auth rejects mismatched server UUID', function () {
    $server = Server::factory()->online()->create();
    $other = Server::factory()->online()->create();
    $token = fake()->uuid();

    Cache::put("terminal:session:{$token}", [
        'server_uuid' => $server->uuid,
        'server_id' => $server->id,
        'user_id' => 1,
        'is_admin' => true,
    ], 60);

    $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $other->uuid,
            'session_token' => $token,
        ])
        ->assertStatus(403);
});

test('terminal page includes available system users', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $server->systemUsers()->create(['username' => 'deployer', 'shell' => '/bin/bash']);

    $this->actingAs($user)
        ->get(route('servers.terminal', $server))
        ->assertInertia(fn ($page) => $page
            ->where('systemUsers', fn ($users) => collect($users)->contains('root') && collect($users)->contains('deployer'))
        );
});

// ---------------------------------------------------------------------------
// Member access — webapp-user-only enforcement (docs/ACCESS_CONTROL.md).
// ---------------------------------------------------------------------------

test('a member assigned to the server can open the terminal page but only sees the webapp user', function () {
    $server = Server::factory()->online()->create();
    $server->systemUsers()->create(['username' => 'deployer', 'shell' => '/bin/bash']);
    $server->systemUsers()->create(['username' => AppTemplates::webappUser(), 'shell' => '/bin/bash']);
    $server->systemUsers()->create(['username' => 'velink-admin', 'shell' => '/bin/bash', 'is_sudo' => true]);

    actingAsMember($server);

    $this->get(route('servers.terminal', $server))
        ->assertInertia(fn ($page) => $page
            ->component('servers/terminal')
            ->where('systemUsers', [AppTemplates::webappUser()])
        );
});

test('a member not assigned to the server cannot open the terminal page', function () {
    $server = Server::factory()->online()->create();

    actingAsMember();

    $this->get(route('servers.terminal', $server))->assertForbidden();
});

test('a member token authorizes only the webapp user', function () {
    $member = User::factory()->member()->create();
    $server = Server::factory()->online()->create();
    $token = fake()->uuid();

    Cache::put("terminal:session:{$token}", [
        'server_uuid' => $server->uuid,
        'server_id' => $server->id,
        'user_id' => $member->id,
        'is_admin' => false,
    ], 60);

    $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $server->uuid,
            'session_token' => $token,
            'user' => AppTemplates::webappUser(),
        ])
        ->assertOk()
        ->assertJson(['valid' => true]);

    // Single-use: consumed by the successful verification.
    expect(Cache::get("terminal:session:{$token}"))->toBeNull();
});

test('a member token rejects root, burns the token, and leaves an audit trail', function () {
    $member = User::factory()->member()->create();
    $server = Server::factory()->online()->create();
    $token = fake()->uuid();

    Cache::put("terminal:session:{$token}", [
        'server_uuid' => $server->uuid,
        'server_id' => $server->id,
        'user_id' => $member->id,
        'is_admin' => false,
    ], 60);

    $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $server->uuid,
            'session_token' => $token,
            'user' => 'root',
        ])
        ->assertStatus(403)
        ->assertJson(['valid' => false]);

    // The token is burnt even on rejection — it must not become a
    // guess-until-luck oracle for the webapp username.
    expect(Cache::get("terminal:session:{$token}"))->toBeNull();

    // The refused privilege escalation is audited, attributed to the member.
    expect(AuditLog::where('action', 'terminal.session_rejected')
        ->where('user_id', $member->id)
        ->where('server_id', $server->id)
        ->exists())->toBeTrue();
});

test('a member token defaults to root when the gateway omits the user param and is rejected', function () {
    $member = User::factory()->member()->create();
    $server = Server::factory()->online()->create();
    $token = fake()->uuid();

    Cache::put("terminal:session:{$token}", [
        'server_uuid' => $server->uuid,
        'server_id' => $server->id,
        'user_id' => $member->id,
        'is_admin' => false,
    ], 60);

    // An empty/absent user means root on the agent side (terminal.go Open()),
    // so the panel must treat it as root — fail closed for members.
    $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $server->uuid,
            'session_token' => $token,
            'user' => '',
        ])
        ->assertStatus(403);
});

test('an admin token authorizes any user including root', function () {
    $admin = User::factory()->create();
    $server = Server::factory()->online()->create();
    $token = fake()->uuid();

    Cache::put("terminal:session:{$token}", [
        'server_uuid' => $server->uuid,
        'server_id' => $server->id,
        'user_id' => $admin->id,
        'is_admin' => true,
    ], 60);

    $this->withHeaders(['X-Gateway-Secret' => config('services.gateway.secret', 'test-gateway-secret')])
        ->postJson('/internal/terminal/auth', [
            'server_uuid' => $server->uuid,
            'session_token' => $token,
            'user' => 'root',
        ])
        ->assertOk()
        ->assertJson(['valid' => true]);
});

test('the generated token carries the requesting user role', function () {
    $server = Server::factory()->online()->create();

    $member = actingAsMember($server);

    $response = $this->getJson(route('servers.terminal', $server))->assertOk();

    $token = $response->json('terminalToken');
    expect($token)->not->toBeNull();

    $session = Cache::get("terminal:session:{$token}");
    expect($session)->not->toBeNull()
        ->and($session['is_admin'])->toBeFalse()
        ->and($session['user_id'])->toBe($member->id);
});
