<?php

use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitProvider;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\DeployTemplates;
use App\Services\DeploymentService;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function mockGatewayPublishCapture(array &$published): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('deploy creates a running deployment and dispatches a shell job for a public repo', function () {
    $published = [];
    mockGatewayPublishCapture($published);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'linux_user' => 'example_app',
        'root_path' => '/home/example_app',
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'deploy_mode' => 'inplace',
    ]);

    $deployment = app(DeploymentService::class)->deploy($application, 'manual', null);

    expect($deployment->status)->toBe('running');
    expect($deployment->mode)->toBe('inplace');
    expect($deployment->branch)->toBe('main');
    expect($deployment->started_at)->not->toBeNull();
    expect($deployment->agent_job_uuid)->not->toBeNull();

    $job = $application->server->agentJobs()->where('application_id', $application->id)->first();
    expect($job->uuid)->toBe($deployment->agent_job_uuid);
    expect($job->type)->toBe('shell');
    expect($job->label)->toContain($application->name);

    $command = $job->payload['command'];
    expect($command)->toContain("sudo -u 'example_app' -H bash -c")
        ->toContain('https://github.com/acme/widgets.git')
        ->toContain('/home/example_app')
        ->toContain('REPO_URL=')
        ->toContain('BRANCH=');

    // Default deploy script is used when none is configured.
    expect($command)->toContain('git fetch --depth 1 origin "$BRANCH"');

    expect($published)->toHaveCount(1);
});

test('deploy embeds the github access token in the repo URL when a credential is configured', function () {
    $published = [];
    mockGatewayPublishCapture($published);

    $user = User::factory()->create();
    $provider = GitProvider::create(['type' => 'github', 'name' => 'GitHub']);
    $credential = $user->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => 'octocat',
        'access_token' => 'ghp_secret',
    ]);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'git_credential_id' => $credential->id,
    ]);

    app(DeploymentService::class)->deploy($application);

    $job = $application->server->agentJobs()->where('application_id', $application->id)->first();
    expect($job->payload['command'])->toContain('https://x-access-token:ghp_secret@github.com/acme/widgets.git');
});

test('deploy embeds the gitlab access token using oauth2 and a custom base url', function () {
    $published = [];
    mockGatewayPublishCapture($published);

    $user = User::factory()->create();
    $provider = GitProvider::create(['type' => 'gitlab', 'name' => 'GitLab', 'base_url' => 'https://gitlab.example.com']);
    $credential = $user->gitCredentials()->create([
        'git_provider_id' => $provider->id,
        'account_username' => 'octocat',
        'access_token' => 'glpat_secret',
    ]);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'group/project',
        'git_credential_id' => $credential->id,
    ]);

    app(DeploymentService::class)->deploy($application);

    $job = $application->server->agentJobs()->where('application_id', $application->id)->first();
    expect($job->payload['command'])->toContain('https://oauth2:glpat_secret@gitlab.example.com/group/project.git');
});

test('deploy uses a custom deploy script when configured', function () {
    $published = [];
    mockGatewayPublishCapture($published);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'deploy_script' => 'echo "custom deploy"',
    ]);

    app(DeploymentService::class)->deploy($application);

    $job = $application->server->agentJobs()->where('application_id', $application->id)->first();
    expect($job->payload['command'])->toContain('echo "custom deploy"')
        ->not->toContain(DeployTemplates::DEFAULT_SCRIPT);
});

test('deploy persists a deployment row regardless of trigger source', function () {
    $published = [];
    mockGatewayPublishCapture($published);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
    ]);

    $deployment = app(DeploymentService::class)->deploy($application, 'webhook', null);

    expect(Deployment::find($deployment->id)->triggered_by)->toBe('webhook');
});
