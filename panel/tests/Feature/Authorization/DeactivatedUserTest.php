<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * Sessions live in Redis, so deactivating a user cannot be enforced by deleting
 * a row — an existing session would otherwise stay valid until it expired.
 */
test('a deactivated user is logged out on their next request', function () {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    expect(Auth::check())->toBeFalse();
});

test('deactivation takes effect mid-session', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('an active user is unaffected', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

test('a deactivated user cannot log in at all', function () {
    $user = User::factory()->inactive()->create(['email' => 'gone@example.test']);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    expect(Auth::check())->toBeFalse();
});

test('an active user can still log in', function () {
    $user = User::factory()->admin()->create(['email' => 'here@example.test']);

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    expect(Auth::id())->toBe($user->id);
});
