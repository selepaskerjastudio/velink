<?php

use App\Models\Application;
use App\Models\GitCredential;
use App\Models\GitProvider;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

function mockGatewayPublishSharing(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

/** A GitHub credential owned by $owner. */
function gitCredentialFor(User $owner, string $username = 'octocat'): GitCredential
{
    $provider = GitProvider::firstOrCreate(['type' => 'github'], ['name' => 'GitHub']);

    return $owner->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => $username,
        'access_token' => 'ghp_test',
    ]);
}

test('the create application page offers a member admin-linked git credentials', function () {
    $server = Server::factory()->online()->create();
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    gitCredentialFor($admin, 'admin-octocat');

    actingAsMember($server);

    $this->get(route('applications.create', $server))
        ->assertInertia(fn ($page) => $page
            ->has('gitCredentials', 1)
            ->where('gitCredentials.0.account_username', 'admin-octocat')
            ->where('gitCredentials.0.shared_by', 'Ada Admin')
        );
});

test('the app deploy tab offers a member admin-linked git credentials', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);
    gitCredentialFor(User::factory()->admin()->create(), 'admin-octocat');

    actingAsMember($server);

    $this->get(route('applications.show', $application))
        ->assertInertia(fn ($page) => $page
            ->has('gitCredentials', 1)
            ->where('gitCredentials.0.account_username', 'admin-octocat')
        );
});

test('a member can create an application using an admin-linked credential', function () {
    mockGatewayPublishSharing();

    $server = Server::factory()->online()->create();
    $credential = gitCredentialFor(User::factory()->admin()->create(), 'admin-octocat');

    actingAsMember($server);

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Shared Repo App',
        'domain' => 'shared.example.com',
        'app_type' => 'custom',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'main',
        'repository' => 'acme/shared',
        'git_credential_id' => $credential->uuid,
    ]);

    $application = Application::firstWhere('domain', 'shared.example.com');
    expect($application)->not->toBeNull();
    expect($application->git_credential_id)->toBe($credential->id);
    $response->assertRedirect(route('applications.show', $application));
});

test('a member can save deploy settings using an admin-linked credential', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);
    $credential = gitCredentialFor(User::factory()->admin()->create(), 'admin-octocat');

    actingAsMember($server);

    $this->patch(route('applications.deploy-settings', $application), [
        'branch' => 'main',
        'deploy_mode' => 'inplace',
        'repository' => 'acme/shared',
        'git_credential_id' => $credential->uuid,
    ])->assertRedirect(route('applications.show', $application));

    expect($application->fresh()->git_credential_id)->toBe($credential->id);
});

test('an admin can use a credential linked by another admin', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);
    $credential = gitCredentialFor(User::factory()->admin()->create(), 'other-admin');

    actingAsAdmin();

    $this->patch(route('applications.deploy-settings', $application), [
        'branch' => 'main',
        'deploy_mode' => 'inplace',
        'repository' => 'acme/shared',
        'git_credential_id' => $credential->uuid,
    ])->assertRedirect(route('applications.show', $application));

    expect($application->fresh()->git_credential_id)->toBe($credential->id);
});

test('git credentials owned by other members are not shared', function () {
    $server = Server::factory()->online()->create();
    $otherMemberCredential = gitCredentialFor(User::factory()->member()->create(), 'other-member');
    $application = Application::factory()->create(['server_id' => $server->id]);

    $member = actingAsMember($server);
    $ownCredential = gitCredentialFor($member, 'own-account');

    // Listing: only their own credential is offered.
    $this->get(route('applications.create', $server))
        ->assertInertia(fn ($page) => $page
            ->has('gitCredentials', 1)
            ->where('gitCredentials.0.account_username', 'own-account')
            ->where('gitCredentials.0.shared_by', null)
        );

    // Deploy settings: another member's credential is rejected.
    $this->patch(route('applications.deploy-settings', $application), [
        'branch' => 'main',
        'deploy_mode' => 'inplace',
        'repository' => 'acme/widgets',
        'git_credential_id' => $otherMemberCredential->uuid,
    ])->assertSessionHasErrors('git_credential_id');

    expect($application->fresh()->git_credential_id)->toBeNull();
});

test('a member can search repos through an admin-linked credential', function () {
    Http::fake([
        'api.github.com/user/repos?*' => Http::response([
            ['full_name' => 'acme/shared', 'private' => true, 'default_branch' => 'main'],
        ]),
    ]);

    $server = Server::factory()->online()->create();
    $credential = gitCredentialFor(User::factory()->admin()->create(), 'admin-octocat');

    actingAsMember($server);

    $this->get(route('github.repos', ['credential' => $credential->uuid]))
        ->assertOk()
        ->assertJsonPath('repos.0.full_name', 'acme/shared');
});

test('repo search rejects a credential owned by another member', function () {
    $server = Server::factory()->online()->create();
    $credential = gitCredentialFor(User::factory()->member()->create(), 'other-member');

    actingAsMember($server);

    $this->get(route('github.repos', ['credential' => $credential->uuid]))->assertNotFound();
});

test('a member cannot delete an admin-linked git credential', function () {
    $server = Server::factory()->online()->create();
    $credential = gitCredentialFor(User::factory()->admin()->create(), 'admin-octocat');

    actingAsMember($server);

    $this->delete(route('git-credentials.destroy', $credential))->assertForbidden();

    expect($credential->fresh())->not->toBeNull();
});

test('the credential management page stays personal for a member', function () {
    $server = Server::factory()->online()->create();
    gitCredentialFor(User::factory()->admin()->create(), 'admin-octocat');

    $member = actingAsMember($server);
    gitCredentialFor($member, 'own-account');

    // Sharing is deploy-only: the management list (with delete buttons)
    // must not surface admin-linked credentials.
    $this->get(route('git-credentials.index'))
        ->assertInertia(fn ($page) => $page
            ->has('credentials', 1)
            ->where('credentials.0.account_username', 'own-account')
        );
});
