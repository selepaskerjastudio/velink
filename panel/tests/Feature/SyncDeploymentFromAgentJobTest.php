<?php

use App\Events\AgentJobUpdated;
use App\Listeners\SyncDeploymentFromAgentJob;
use App\Models\AgentJob;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('a running job updates the matching deployment to running with its output', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);
    $job = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);
    $job->appendOutput("cloning...\n");

    $deployment = Deployment::create([
        'application_id' => $application->id,
        'branch' => 'main',
        'mode' => 'inplace',
        'status' => 'pending',
        'triggered_by' => 'manual',
        'agent_job_uuid' => $job->uuid,
    ]);

    app(SyncDeploymentFromAgentJob::class)->handle(new AgentJobUpdated($job->refresh()));

    $deployment->refresh();
    expect($deployment->status)->toBe('running');
    expect($deployment->log)->toBe("cloning...\n");
    expect($deployment->finished_at)->toBeNull();
});

test('a succeeded job marks the deployment as success and sets finished_at', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);
    $job = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);

    $deployment = Deployment::create([
        'application_id' => $application->id,
        'branch' => 'main',
        'mode' => 'inplace',
        'status' => 'running',
        'triggered_by' => 'manual',
        'agent_job_uuid' => $job->uuid,
    ]);

    $job->markSucceeded(0);

    app(SyncDeploymentFromAgentJob::class)->handle(new AgentJobUpdated($job->refresh()));

    $deployment->refresh();
    expect($deployment->status)->toBe('success');
    expect($deployment->finished_at)->not->toBeNull();
});

test('a failed or timed-out job marks the deployment as failed', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id]);
    $job = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);

    $deployment = Deployment::create([
        'application_id' => $application->id,
        'branch' => 'main',
        'mode' => 'inplace',
        'status' => 'running',
        'triggered_by' => 'manual',
        'agent_job_uuid' => $job->uuid,
    ]);

    $job->markFailed(1, 'composer install failed');

    app(SyncDeploymentFromAgentJob::class)->handle(new AgentJobUpdated($job->refresh()));

    $deployment->refresh();
    expect($deployment->status)->toBe('failed');
    expect($deployment->finished_at)->not->toBeNull();
});

test('a succeeded deploy recovers an app stuck in failed back to active', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id, 'status' => 'failed']);
    $job = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);

    Deployment::create([
        'application_id' => $application->id,
        'branch' => 'main',
        'mode' => 'inplace',
        'status' => 'running',
        'triggered_by' => 'manual',
        'agent_job_uuid' => $job->uuid,
    ]);

    $job->markSucceeded(0);

    app(SyncDeploymentFromAgentJob::class)->handle(new AgentJobUpdated($job->refresh()));

    expect($application->refresh()->status)->toBe('active');
});

test('a succeeded deploy does not reopen the lifecycle of a provisioning app', function () {
    $server = Server::factory()->create();
    $application = Application::factory()->create(['server_id' => $server->id, 'status' => 'provisioning']);
    $job = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);

    Deployment::create([
        'application_id' => $application->id,
        'branch' => 'main',
        'mode' => 'inplace',
        'status' => 'running',
        'triggered_by' => 'manual',
        'agent_job_uuid' => $job->uuid,
    ]);

    $job->markSucceeded(0);

    app(SyncDeploymentFromAgentJob::class)->handle(new AgentJobUpdated($job->refresh()));

    expect($application->refresh()->status)->toBe('provisioning');
});

test('a job without a matching deployment is ignored', function () {
    $server = Server::factory()->create();
    $job = AgentJob::factory()->for($server)->running()->create();

    app(SyncDeploymentFromAgentJob::class)->handle(new AgentJobUpdated($job));

    expect(Deployment::count())->toBe(0);
});
