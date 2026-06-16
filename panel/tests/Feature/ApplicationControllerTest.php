<?php

use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitProvider;
use App\Models\PhpPool;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\DeployTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();

    $this->get(route('applications.create', $server))->assertRedirect('/login');
    $this->post(route('applications.store', $server), [])->assertRedirect('/login');
});

test('authenticated users can view the create application page', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $this->get(route('applications.create', $server))->assertOk();
});

test('an application can be created and provisions a php pool plus jobs', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('applications.store', $server), [
        'name' => 'My App',
        'domain' => 'my-app.example.com',
        'app_type' => 'custom',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'main',
    ]);

    $application = Application::firstWhere('domain', 'my-app.example.com');

    $response->assertRedirect(route('applications.show', $application));
    expect($application)->not->toBeNull();
    expect($application->server_id)->toBe($server->id);
    expect($application->name)->toBe('My App');
    expect($application->app_type)->toBe('custom');
    expect($application->php_version)->toBe('8.3');
    expect($application->status)->toBe('provisioning');
    expect($application->linux_user)->toBe('velink');
    expect($application->app_slug)->toMatch('/^[a-z][a-z0-9_]*$/');
    expect($application->root_path)->toBe("/home/velink/webapps/{$application->app_slug}");

    expect($application->server->agentJobs()->where('application_id', $application->id)->count())->toBe(5);
    expect(PhpPool::where('application_id', $application->id)->where('php_version', '8.3')->exists())->toBeTrue();
});

test('a static application is created without a php pool', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Static Site',
        'domain' => 'static.example.com',
        'app_type' => 'static',
        'stack_mode' => 'production',
        'branch' => 'main',
        // no php_version — not required for static
    ]);

    $application = Application::firstWhere('domain', 'static.example.com');

    $response->assertRedirect(route('applications.show', $application));
    expect($application->app_type)->toBe('static');
    expect(PhpPool::where('application_id', $application->id)->exists())->toBeFalse();
});

test('a wordpress application requires a database engine', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    // No DB engine installed on this server.
    $server = Server::factory()->online()->create();

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Blog',
        'domain' => 'blog.example.com',
        'app_type' => 'wordpress',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'main',
        'db_engine' => 'mariadb',
        'db_name' => 'blog_db',
        'db_username' => 'blog_user',
    ]);

    // mariadb is not installed → db_engine fails the in:installedEngines rule.
    $response->assertSessionHasErrors('db_engine');
    expect(Application::where('domain', 'blog.example.com')->exists())->toBeFalse();
});

test('an application can be created with a database and user', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    // Mark mariadb as installed/running so it is an allowed engine.
    $server->services()->create(['name' => 'mariadb', 'type' => 'database', 'status' => 'running']);

    $response = $this->post(route('applications.store', $server), [
        'name' => 'App With DB',
        'domain' => 'withdb.example.com',
        'app_type' => 'laravel',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'main',
        'create_database' => true,
        'db_engine' => 'mariadb',
        'db_name' => 'app_db',
        'db_username' => 'app_user',
    ]);

    $application = Application::firstWhere('domain', 'withdb.example.com');
    $response->assertRedirect(route('applications.show', $application));

    expect($server->databases()->where('name', 'app_db')->where('engine', 'mariadb')->exists())->toBeTrue();
    expect($server->databaseUsers()->where('username', 'app_user')->where('engine', 'mariadb')->exists())->toBeTrue();

    // The app's .env is seeded with the real DB credentials (not the framework
    // default) so the first deploy's migrate connects as app_user@localhost.
    $env = $application->env_content;
    expect($env)->toContain('DB_CONNECTION=mysql');
    expect($env)->toContain('DB_HOST=localhost');
    expect($env)->toContain('DB_DATABASE=app_db');
    expect($env)->toContain('DB_USERNAME=app_user');
    expect($env)->toMatch('/DB_PASSWORD=.+/');
});

test('a reserved database name is rejected', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $server->services()->create(['name' => 'mariadb', 'type' => 'database', 'status' => 'running']);

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Bad DB',
        'domain' => 'baddb.example.com',
        'app_type' => 'custom',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'main',
        'create_database' => true,
        'db_engine' => 'mariadb',
        'db_name' => 'mysql',
        'db_username' => 'app_user',
    ]);

    $response->assertSessionHasErrors('db_name');
    expect(Application::where('domain', 'baddb.example.com')->exists())->toBeFalse();
});

test('git settings can be configured at creation', function () {
    mockGatewayPublish();

    $user = User::factory()->create();
    $this->actingAs($user);
    $server = Server::factory()->online()->create();

    $provider = GitProvider::create(['type' => 'github', 'name' => 'GitHub']);
    $credential = $user->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => 'octocat',
        'access_token' => 'ghp_test',
    ]);

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Git App',
        'domain' => 'gitapp.example.com',
        'app_type' => 'custom',
        'stack_mode' => 'production',
        'php_version' => '8.3',
        'branch' => 'develop',
        'repository' => 'acme/widgets',
        'git_credential_id' => $credential->uuid,
    ]);

    $application = Application::firstWhere('domain', 'gitapp.example.com');
    $response->assertRedirect(route('applications.show', $application));
    expect($application->repository)->toBe('acme/widgets');
    expect($application->branch)->toBe('develop');
    expect($application->git_credential_id)->toBe($credential->id);
});

test('application domain must be unique', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    Application::factory()->create(['server_id' => $server->id, 'domain' => 'taken.example.com']);

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Other App',
        'domain' => 'taken.example.com',
        'php_version' => '8.3',
    ]);

    $response->assertSessionHasErrors('domain');
});

test('application domain must match the hostname format', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('applications.store', $server), [
        'name' => 'Bad App',
        'domain' => 'not a domain',
        'php_version' => '8.3',
    ]);

    $response->assertSessionHasErrors('domain');
});

test('php version must be a supported catalog version', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();

    $response = $this->post(route('applications.store', $server), [
        'name' => 'My App',
        'domain' => 'my-app.example.com',
        'php_version' => '5.6',
    ]);

    $response->assertSessionHasErrors('php_version');
});

test('an application show page can be viewed', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $this->get(route('applications.show', $application))->assertOk();
});

test('php version can be switched and dispatches the change job sequence', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id, 'php_version' => '8.3']);

    PhpPool::create([
        'application_id' => $application->id,
        'php_version' => '8.3',
        'pool_name' => $application->app_slug,
        'socket_path' => "/run/php/{$application->app_slug}.sock",
        'config' => [],
    ]);

    $response = $this->patch(route('applications.php-version', $application), [
        'php_version' => '8.4',
    ]);

    $response->assertRedirect(route('applications.show', $application));
    expect($application->refresh()->php_version)->toBe('8.4');
    expect($application->server->agentJobs()->where('application_id', $application->id)->count())->toBe(3);
});

test('switching to the current php version does not dispatch jobs', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id, 'php_version' => '8.3']);

    $response = $this->patch(route('applications.php-version', $application), [
        'php_version' => '8.3',
    ]);

    $response->assertRedirect(route('applications.show', $application));
    expect($application->server->agentJobs()->where('application_id', $application->id)->count())->toBe(0);
});

test('env content can be updated and is written to the server', function () {
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $response = $this->patch(route('applications.env', $application), [
        'env_content' => "APP_NAME=Test\nAPP_ENV=production",
    ]);

    $response->assertRedirect(route('applications.show', $application));
    expect($application->refresh()->env_content)->toBe("APP_NAME=Test\nAPP_ENV=production");

    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['action'])->toBe('write_file');
    expect($published[0]['payload']['params']['path'])->toBe("{$application->root_path}/.env");
});

test('deploy settings can be updated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $provider = GitProvider::create(['type' => 'github', 'name' => 'GitHub']);
    $credential = $user->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => 'octocat',
        'access_token' => 'ghp_test',
    ]);

    $response = $this->patch(route('applications.deploy-settings', $application), [
        'repository' => 'acme/widgets',
        'branch' => 'develop',
        'deploy_mode' => 'inplace',
        'git_credential_id' => $credential->uuid,
        'deploy_script' => 'echo deploy',
    ]);

    $response->assertRedirect(route('applications.show', $application));

    $application->refresh();
    expect($application->repository)->toBe('acme/widgets');
    expect($application->branch)->toBe('develop');
    expect($application->deploy_mode)->toBe('inplace');
    expect($application->git_credential_id)->toBe($credential->id);
    expect($application->deploy_script)->toBe('echo deploy');
});

test('deploy settings reject an invalid repository format', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $response = $this->patch(route('applications.deploy-settings', $application), [
        'repository' => 'not a repo',
        'branch' => 'main',
        'deploy_mode' => 'inplace',
    ]);

    $response->assertSessionHasErrors('repository');
});

test('deploy settings reject zero-downtime mode for now', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $response = $this->patch(route('applications.deploy-settings', $application), [
        'branch' => 'main',
        'deploy_mode' => 'zero_downtime',
    ]);

    $response->assertSessionHasErrors('deploy_mode');
});

test('a git credential must belong to the authenticated user', function () {
    $owner = User::factory()->create();
    $provider = GitProvider::create(['type' => 'github', 'name' => 'GitHub']);
    $credential = $owner->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => 'octocat',
        'access_token' => 'ghp_test',
    ]);

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $response = $this->patch(route('applications.deploy-settings', $application), [
        'branch' => 'main',
        'deploy_mode' => 'inplace',
        'git_credential_id' => $credential->uuid,
    ]);

    $response->assertSessionHasErrors('git_credential_id');
});

test('a deployment can be triggered when a repository is configured', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
    ]);

    $response = $this->post(route('applications.deployments.store', $application));

    $response->assertRedirect(route('applications.show', $application));

    $deployment = Deployment::where('application_id', $application->id)->first();
    expect($deployment)->not->toBeNull();
    expect($deployment->status)->toBe('running');
    expect($deployment->branch)->toBe('main');
    expect($deployment->mode)->toBe('inplace');
    expect($deployment->triggered_by)->toBe('manual');
    expect($deployment->agent_job_uuid)->not->toBeNull();

    $job = $application->server->agentJobs()->where('application_id', $application->id)->first();
    expect($job->uuid)->toBe($deployment->agent_job_uuid);
    expect($job->payload['command'])->toContain('acme/widgets')->toContain('main');
});

test('deploying without a repository configured returns an error', function () {
    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $response = $this->post(route('applications.deployments.store', $application));

    $response->assertRedirect(route('applications.show', $application));
    $response->assertSessionHasErrors('repository');

    expect(Deployment::where('application_id', $application->id)->count())->toBe(0);
});

test('show page includes deploy settings, git credentials and deployment history', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);

    $provider = GitProvider::create(['type' => 'github', 'name' => 'GitHub']);
    $user->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => 'octocat',
        'access_token' => 'ghp_test',
    ]);

    Deployment::create([
        'application_id' => $application->id,
        'branch' => 'main',
        'mode' => 'inplace',
        'status' => 'success',
        'triggered_by' => 'manual',
    ]);

    $response = $this->get(route('applications.show', $application));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('gitCredentials', 1)
        ->has('deployments', 1)
        ->where('defaultDeployScript', DeployTemplates::DEFAULT_SCRIPT)
        ->where('application.deploy_mode', 'inplace')
    );
});

test('an application can be deleted with the DELETE confirmation and tears down its records', function () {
    mockGatewayPublish();

    $this->actingAs(User::factory()->create());
    $server = Server::factory()->online()->create();
    $application = Application::factory()->for($server)->create();
    PhpPool::create([
        'application_id' => $application->id,
        'php_version' => $application->php_version,
        'pool_name' => $application->app_slug,
        'socket_path' => "/run/php/{$application->app_slug}.sock",
        'config' => [],
    ]);

    $response = $this->delete(route('applications.destroy', $application), [
        'confirmation' => 'DELETE',
    ]);

    $response->assertRedirect(route('applications.server-index', $server));
    expect(Application::find($application->id))->toBeNull();
    expect(PhpPool::where('application_id', $application->id)->count())->toBe(0);
});

test('an application is not deleted without the DELETE confirmation', function () {
    $this->actingAs(User::factory()->create());
    $application = Application::factory()->create();

    $this->from(route('applications.show', $application))
        ->delete(route('applications.destroy', $application), ['confirmation' => 'delete'])
        ->assertSessionHasErrors('confirmation');

    expect(Application::find($application->id))->not->toBeNull();
});
