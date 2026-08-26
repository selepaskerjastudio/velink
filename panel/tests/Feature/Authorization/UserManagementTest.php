<?php

use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

test('members and non-admins cannot reach user management at all', function () {
    $this->actingAs(User::factory()->member()->create())
        ->get(route('users.index'))
        ->assertForbidden();
});

// ── Invite → accept ─────────────────────────────────────────────────────

test('an admin can invite a user with pre-assigned servers, via a copy link since mail is not configured', function () {
    $server = Server::factory()->create();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('invitations.store'), [
        'email' => 'newmember@example.test',
        'role' => 'member',
        'server_uuids' => [$server->uuid],
    ]);

    $response->assertRedirect(route('users.index'));

    $invitation = UserInvitation::where('email', 'newmember@example.test')->firstOrFail();
    expect($invitation->role)->toBe('member');
    expect($invitation->server_ids)->toBe([$server->id]);
    expect($invitation->expires_at)->not->toBeNull();

    // config('mail.default') is 'log' in tests, so no mail is attempted — the
    // flashed link is the only delivery path and must always be present.
    $inviteUrl = Session::get('invite_url');
    expect($inviteUrl)->not->toBeNull();
    expect($inviteUrl)->toContain('/invitations/');

    expect(AuditLog::where('action', 'user.invited')->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('inviting an email that already has a user is rejected', function () {
    $existing = User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('invitations.store'), ['email' => $existing->email, 'role' => 'member'])
        ->assertStatus(422);
});

test('inviting an email with an already-pending invitation is rejected', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('invitations.store'), ['email' => 'dup@example.test', 'role' => 'member']);

    $this->actingAs($admin)
        ->post(route('invitations.store'), ['email' => 'dup@example.test', 'role' => 'member'])
        ->assertStatus(422);

    expect(UserInvitation::where('email', 'dup@example.test')->count())->toBe(1);
});

test('accepting an invitation creates the user with the invited role and servers, and logs them in', function () {
    $server = Server::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('invitations.store'), [
        'email' => 'invitee@example.test',
        'role' => 'member',
        'server_uuids' => [$server->uuid],
    ]);

    $plainToken = explode('/', rtrim((string) Session::get('invite_url'), '/'));
    $plainToken = end($plainToken);

    // Back to a guest: invitations.accept is 'guest'-only, and the admin
    // session from creating the invite above is still active otherwise.
    $this->post(route('logout'));

    $this->get(route('invitations.accept', $plainToken))->assertOk();

    $response = $this->post(route('invitations.accept.store', $plainToken), [
        'name' => 'Invitee Person',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::where('email', 'invitee@example.test')->firstOrFail();
    expect($user->role)->toBe('member');
    expect($user->is_active)->toBeTrue();
    expect($user->servers()->pluck('servers.id')->all())->toBe([$server->id]);

    $invitation = UserInvitation::where('email', 'invitee@example.test')->firstOrFail();
    expect($invitation->accepted_at)->not->toBeNull();

    expect(AuditLog::where('action', 'user.invite_accepted')->where('user_id', $user->id)->exists())->toBeTrue();
});

test('an already-accepted or expired invitation token is refused', function () {
    $admin = User::factory()->admin()->create();
    $invitation = UserInvitation::create([
        'uuid' => (string) Str::uuid(),
        'email' => 'gone@example.test',
        'role' => 'member',
        'token' => UserInvitation::hashToken('plain-token-value'),
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->subDay(),
    ]);

    $this->get(route('invitations.accept', 'plain-token-value'))->assertNotFound();
});

// ── Admin management + anti-lockout guards ──────────────────────────────

test('an admin can change a members role and deactivate them', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->member()->create();

    $this->actingAs($admin)->patch(route('users.update', $member), [
        'role' => 'admin',
        'is_active' => false,
    ])->assertRedirect(route('users.index'));

    $member->refresh();
    expect($member->role)->toBe('admin');
    expect($member->is_active)->toBeFalse();

    expect(AuditLog::where('action', 'user.role_changed')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'user.deactivated')->exists())->toBeTrue();
});

test('an admin cannot demote themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('users.update', $admin), ['role' => 'member', 'is_active' => true])
        ->assertStatus(422);

    expect($admin->fresh()->role)->toBe('admin');
});

test('an admin cannot deactivate themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('users.update', $admin), ['role' => 'admin', 'is_active' => false])
        ->assertStatus(422);

    expect($admin->fresh()->is_active)->toBeTrue();
});

test('a different admin CAN demote the only other admin, since the actor remains active', function () {
    // There is no separate "last admin" guard beyond the self-guards above —
    // see the comment on UserController::update(). Whoever performs this
    // request is themselves an active admin (enforced by middleware), so the
    // panel is never left admin-less by a cross-actor action.
    $onlyOtherAdmin = User::factory()->admin()->create();
    $actingAdmin = User::factory()->admin()->create();

    $this->actingAs($actingAdmin)
        ->patch(route('users.update', $onlyOtherAdmin), ['role' => 'member', 'is_active' => true])
        ->assertRedirect(route('users.index'));

    expect($onlyOtherAdmin->fresh()->role)->toBe('member');
    expect(User::where('role', 'admin')->where('is_active', true)->count())->toBe(1);
});

test('an admin cannot delete their own account', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('users.destroy', $admin))
        ->assertStatus(422);

    expect(User::find($admin->id))->not->toBeNull();
});

test('deleting a non-self admin succeeds and is audited', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('users.destroy', $target))
        ->assertRedirect(route('users.index'));

    expect(User::find($target->id))->toBeNull();
    expect(AuditLog::where('action', 'user.deleted')->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('syncing server assignments attaches and detaches, and audits both', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->member()->create();
    $keep = Server::factory()->create();
    $drop = Server::factory()->create();
    $add = Server::factory()->create();
    $member->servers()->attach([$keep->id, $drop->id]);

    $this->actingAs($admin)->put(route('users.servers.sync', $member), [
        'server_uuids' => [$keep->uuid, $add->uuid],
    ])->assertRedirect(route('users.index'));

    $ids = $member->servers()->pluck('servers.id')->sort()->values()->all();
    expect($ids)->toBe(collect([$keep->id, $add->id])->sort()->values()->all());

    expect(AuditLog::where('action', 'user.server_assigned')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'user.server_unassigned')->exists())->toBeTrue();
});

test('revoking a pending invitation removes it', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('invitations.store'), ['email' => 'revoke-me@example.test', 'role' => 'member']);
    $invitation = UserInvitation::where('email', 'revoke-me@example.test')->firstOrFail();

    $this->actingAs($admin)
        ->delete(route('invitations.destroy', $invitation))
        ->assertRedirect(route('users.index'));

    expect(UserInvitation::find($invitation->id))->toBeNull();
    expect(AuditLog::where('action', 'user.invite_revoked')->exists())->toBeTrue();
});
