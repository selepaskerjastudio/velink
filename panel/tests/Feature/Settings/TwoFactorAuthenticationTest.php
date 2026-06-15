<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('two-factor settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/settings/two-factor');

    $response->assertStatus(200);
});

test('two-factor authentication can be enabled and confirmed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/two-factor/enable');

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $code = (new Google2FA())->getCurrentOtp($user->two_factor_secret);

    $this->actingAs($user)->post('/settings/two-factor/confirm', [
        'code' => $code,
    ]);

    $user->refresh();
    expect($user->hasEnabledTwoFactorAuthentication())->toBeTrue();
    expect($user->two_factor_recovery_codes)->toHaveCount(8);
});

test('login redirects to two-factor challenge when enabled', function () {
    $user = User::factory()->create();
    $secret = (new Google2FA())->generateSecretKey();

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['recovery-code-1', 'recovery-code-2'],
    ])->save();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('two-factor.login'));

    $code = (new Google2FA())->getCurrentOtp($secret);

    $challenge = $this->post('/two-factor-challenge', [
        'code' => $code,
    ]);

    $this->assertAuthenticatedAs($user);
    $challenge->assertRedirect(route('dashboard', absolute: false));
});

test('login with two-factor can use a recovery code', function () {
    $user = User::factory()->create();
    $secret = (new Google2FA())->generateSecretKey();

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['recovery-code-1', 'recovery-code-2'],
    ])->save();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $challenge = $this->post('/two-factor-challenge', [
        'recovery_code' => 'recovery-code-1',
    ]);

    $this->assertAuthenticatedAs($user);
    $challenge->assertRedirect(route('dashboard', absolute: false));

    $user->refresh();
    expect($user->two_factor_recovery_codes)->toBe(['recovery-code-2']);
});

test('two-factor authentication can be disabled', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => (new Google2FA())->generateSecretKey(),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['code-1'],
    ])->save();

    $this->actingAs($user)->delete('/settings/two-factor');

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->two_factor_recovery_codes)->toBeNull();
});
