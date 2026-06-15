<?php

use App\Models\GitCredential;
use App\Models\GitProvider;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('git-credentials.index'))->assertRedirect('/login');
    $this->post(route('git-credentials.store'), [])->assertRedirect('/login');
});

test('authenticated users can view their git credentials', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('git-credentials.index'))->assertOk();
});

test('a personal access token can be added and is encrypted at rest', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post(route('git-credentials.store'), [
        'provider_type' => 'github',
        'account_username' => 'octocat',
        'access_token' => 'ghp_supersecret',
    ]);

    $response->assertRedirect(route('git-credentials.index'));

    $credential = GitCredential::where('user_id', $user->id)->first();
    expect($credential)->not->toBeNull();
    expect($credential->account_username)->toBe('octocat');
    expect($credential->access_token)->toBe('ghp_supersecret');
    expect($credential->provider->type)->toBe('github');

    $raw = \Illuminate\Support\Facades\DB::table('git_credentials')->where('id', $credential->id)->first();
    expect($raw->access_token)->not->toBe('ghp_supersecret');

    $provider = GitProvider::where('type', 'github')->first();
    expect($provider)->not->toBeNull();
    expect($provider->name)->toBe('Github');
});

test('reusing the same provider type does not create duplicate providers', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('git-credentials.store'), [
        'provider_type' => 'github',
        'account_username' => 'octocat',
        'access_token' => 'ghp_one',
    ]);
    $this->post(route('git-credentials.store'), [
        'provider_type' => 'github',
        'account_username' => 'octocat-2',
        'access_token' => 'ghp_two',
    ]);

    expect(GitProvider::where('type', 'github')->count())->toBe(1);
    expect(GitCredential::where('user_id', $user->id)->count())->toBe(2);
});

test('provider type must be supported', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->post(route('git-credentials.store'), [
        'provider_type' => 'bitbucket',
        'access_token' => 'token',
    ]);

    $response->assertSessionHasErrors('provider_type');
});

test('a user can remove their own git credential', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $provider = GitProvider::create(['type' => 'github', 'name' => 'GitHub']);
    $credential = $user->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => 'octocat',
        'access_token' => 'ghp_test',
    ]);

    $response = $this->delete(route('git-credentials.destroy', $credential));

    $response->assertRedirect(route('git-credentials.index'));
    expect(GitCredential::find($credential->id))->toBeNull();
});

test('a user cannot remove another users git credential', function () {
    $owner = User::factory()->create();
    $provider = GitProvider::create(['type' => 'github', 'name' => 'GitHub']);
    $credential = $owner->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => 'octocat',
        'access_token' => 'ghp_test',
    ]);

    $this->actingAs(User::factory()->create());

    $response = $this->delete(route('git-credentials.destroy', $credential));

    $response->assertForbidden();
    expect(GitCredential::find($credential->id))->not->toBeNull();
});
