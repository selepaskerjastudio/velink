<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The users.role column defaults to 'member' (Langkah 1 §4.1) so new accounts
 * default to least privilege. The very first account is the one exception —
 * without an admin, nobody could ever reach /settings/users to promote anyone.
 */
test('the first registered user is an admin', function () {
    $this->post('/register', [
        'name' => 'First User',
        'email' => 'first@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'first@example.test')->firstOrFail();

    expect($user->role)->toBe(User::ROLE_ADMIN);
    expect($user->is_active)->toBeTrue();
});

test('registration is closed once a user exists, regardless of role', function () {
    User::factory()->member()->create();

    $this->get('/register')->assertForbidden();
    $this->post('/register', [
        'name' => 'Second User',
        'email' => 'second@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertForbidden();

    expect(User::where('email', 'second@example.test')->exists())->toBeFalse();
});
