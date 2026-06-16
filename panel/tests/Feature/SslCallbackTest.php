<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use App\Services\GatewayInboundProcessor;
use App\Services\JobDispatcher;
use App\Services\ProvisionService;
use App\Services\ServiceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ──────────────────────────────────────────────────────
// SSL callback: certbot job_result updates ssl_status
// ──────────────────────────────────────────────────────

test('ssl enable job success updates ssl_status to active', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'requesting',
    ]);

    $job = AgentJob::factory()->create([
        'server_id' => $server->id,
        'application_id' => $application->id,
        'type' => 'shell',
        'label' => "Enable SSL for {$application->domain}",
        'status' => AgentJob::STATUS_DISPATCHED,
    ]);

    $processor = new GatewayInboundProcessor(
        app(JobDispatcher::class),
        app(ServiceManager::class),
        app(ProvisionService::class),
    );

    $processor->handleInbound(json_encode([
        'type' => 'job_result',
        'job_id' => $job->uuid,
        'payload' => [
            'exit_code' => 0,
        ],
    ]));

    $application->refresh();
    expect($application->ssl_status)->toBe('active');

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_SUCCEEDED);
});

test('ssl enable job failure updates ssl_status to failed', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'requesting',
    ]);

    $job = AgentJob::factory()->create([
        'server_id' => $server->id,
        'application_id' => $application->id,
        'type' => 'shell',
        'label' => "Enable SSL for {$application->domain}",
        'status' => AgentJob::STATUS_RUNNING,
    ]);

    $processor = new GatewayInboundProcessor(
        app(JobDispatcher::class),
        app(ServiceManager::class),
        app(ProvisionService::class),
    );

    $processor->handleInbound(json_encode([
        'type' => 'job_result',
        'job_id' => $job->uuid,
        'payload' => [
            'exit_code' => 1,
            'error' => 'Could not obtain certificate',
        ],
    ]));

    $application->refresh();
    expect($application->ssl_status)->toBe('failed');

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_FAILED);
});

test('ssl check job with valid cert output updates ssl_status to active', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'none',
    ]);

    $job = AgentJob::factory()->create([
        'server_id' => $server->id,
        'application_id' => $application->id,
        'type' => 'shell',
        'label' => "Check SSL for {$application->domain}",
        'status' => AgentJob::STATUS_RUNNING,
        'output' => "Expiry Date: 2027-01-01T00:00:00Z\nCertificate Path: /etc/letsencrypt/live/myapp.example.com/fullchain.pem",
    ]);

    $processor = new GatewayInboundProcessor(
        app(JobDispatcher::class),
        app(ServiceManager::class),
        app(ProvisionService::class),
    );

    $processor->handleInbound(json_encode([
        'type' => 'job_result',
        'job_id' => $job->uuid,
        'payload' => [
            'exit_code' => 0,
        ],
    ]));

    $application->refresh();
    expect($application->ssl_status)->toBe('active');
});

test('ssl check job with not_found output updates ssl_status to none', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'failed',
    ]);

    $job = AgentJob::factory()->create([
        'server_id' => $server->id,
        'application_id' => $application->id,
        'type' => 'shell',
        'label' => "Check SSL for {$application->domain}",
        'status' => AgentJob::STATUS_RUNNING,
        'output' => 'NOT_FOUND',
    ]);

    $processor = new GatewayInboundProcessor(
        app(JobDispatcher::class),
        app(ServiceManager::class),
        app(ProvisionService::class),
    );

    $processor->handleInbound(json_encode([
        'type' => 'job_result',
        'job_id' => $job->uuid,
        'payload' => [
            'exit_code' => 0,
        ],
    ]));

    $application->refresh();
    expect($application->ssl_status)->toBe('none');
});

test('non-ssl job result does not affect ssl_status', function () {
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'domain' => 'myapp.example.com',
        'status' => 'active',
        'ssl_status' => 'requesting',
    ]);

    $job = AgentJob::factory()->create([
        'server_id' => $server->id,
        'application_id' => $application->id,
        'type' => 'shell',
        'label' => 'Deploy application',
        'status' => AgentJob::STATUS_RUNNING,
    ]);

    $processor = new GatewayInboundProcessor(
        app(JobDispatcher::class),
        app(ServiceManager::class),
        app(ProvisionService::class),
    );

    $processor->handleInbound(json_encode([
        'type' => 'job_result',
        'job_id' => $job->uuid,
        'payload' => [
            'exit_code' => 0,
        ],
    ]));

    $application->refresh();
    expect($application->ssl_status)->toBe('requesting');
});

test('ssl job without application_id does not crash', function () {
    $server = Server::factory()->online()->create();

    $job = AgentJob::factory()->create([
        'server_id' => $server->id,
        'application_id' => null,
        'type' => 'shell',
        'label' => "Enable SSL for some.domain.com",
        'status' => AgentJob::STATUS_RUNNING,
    ]);

    $processor = new GatewayInboundProcessor(
        app(JobDispatcher::class),
        app(ServiceManager::class),
        app(ProvisionService::class),
    );

    // Should not throw — just mark job as succeeded
    $processor->handleInbound(json_encode([
        'type' => 'job_result',
        'job_id' => $job->uuid,
        'payload' => [
            'exit_code' => 0,
        ],
    ]));

    $job->refresh();
    expect($job->status)->toBe(AgentJob::STATUS_SUCCEEDED);
});
