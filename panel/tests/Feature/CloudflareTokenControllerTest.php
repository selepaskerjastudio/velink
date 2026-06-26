<?php

use App\Models\AuditLog;
use App\Models\CloudflareToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CF_API = 'https://api.cloudflare.com/client/v4';

test('guests are redirected to the login page', function () {
    $this->get(route('cloudflare.index'))->assertRedirect('/login');
    $this->post(route('cloudflare.store'))->assertRedirect('/login');
});

test('the cloudflare settings page lists tokens', function () {
    $user = User::factory()->create();
    CloudflareToken::create([
        'user_id' => $user->id, 'email' => 'admin@example.com',
        'api_token' => 'secret', 'verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('cloudflare.index'))
        ->assertInertia(fn ($page) => $page
            ->component('settings/cloudflare')
            ->has('tokens', 1)
            ->where('tokens.0.email', 'admin@example.com')
            ->where('tokens.0.verified', true)
        );
});

test('store validates the token against the CF API before saving', function () {
    Http::fake([
        CF_API.'/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']], 200),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('cloudflare.store'), [
            'email' => 'admin@example.com',
            'api_token' => 'valid-token-123',
        ])
        ->assertRedirect(route('cloudflare.index'));

    $token = CloudflareToken::where('user_id', $user->id)->first();
    expect($token)->not->toBeNull()
        ->and($token->email)->toBe('admin@example.com')
        ->and($token->verified_at)->not->toBeNull();

    expect(AuditLog::where('action', 'cloudflare.token_added')->exists())->toBeTrue();
});

test('store rejects an invalid token', function () {
    Http::fake([
        CF_API.'/user/tokens/verify' => Http::response(['success' => false, 'errors' => [['message' => 'Unauthorized']]], 401),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('cloudflare.index'))
        ->post(route('cloudflare.store'), [
            'email' => 'bad@example.com',
            'api_token' => 'invalid',
        ])
        ->assertSessionHasErrors('api_token');

    expect(CloudflareToken::count())->toBe(0);
});

test('a user cannot delete a token owned by someone else', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $token = CloudflareToken::create([
        'user_id' => $owner->id, 'email' => 'owner@example.com',
        'api_token' => 'secret', 'verified_at' => now(),
    ]);

    $this->actingAs($intruder)
        ->delete(route('cloudflare.destroy', $token))
        ->assertForbidden();

    expect(CloudflareToken::find($token->id))->not->toBeNull();
});

test('destroy removes the token', function () {
    $user = User::factory()->create();
    $token = CloudflareToken::create([
        'user_id' => $user->id, 'email' => 'admin@example.com',
        'api_token' => 'secret', 'verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete(route('cloudflare.destroy', $token))
        ->assertRedirect(route('cloudflare.index'));

    expect(CloudflareToken::find($token->id))->toBeNull();
});
