<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockDirSizeGatewayPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('application show exposes the stored directory size', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'directory_size_bytes' => 4831838208, // ~4.5 GiB
    ]);

    $response = $this->get(route('applications.show', $application));

    $response->assertInertia(fn ($page) => $page
        ->where('application.directory_size_bytes', 4831838208)
    );
});

test('refresh directory size dispatches a du job scoped to the app root', function () {
    mockDirSizeGatewayPublish();

    $user = User::factory()->create();
    $this->actingAs($user);

    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'root_path' => '/home/velink/app_myapp',
    ]);

    $this->post(route('applications.directory-size', $application))
        ->assertRedirect(route('applications.show', $application));

    $job = AgentJob::where('application_id', $application->id)->latest('id')->first();

    expect($job)->not->toBeNull()
        ->and($job->type)->toBe('shell')
        ->and($job->payload['command'])->toContain('du -sb')
        ->and($job->payload['command'])->toContain('/home/velink/app_myapp')
        ->and($job->label)->toBe('Measure directory size');
});

test('a completed du job result persists the byte count on the application', function () {
    // Simulate the agent reporting a successful `du -sb` back through the gateway.
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'directory_size_bytes' => null,
    ]);

    // Seed the originating job so the inbound processor has something to resolve.
    $job = AgentJob::factory()->create([
        'server_id' => $server->id,
        'application_id' => $application->id,
        'type' => 'shell',
        'payload' => ['command' => 'du -sb '.$application->root_path],
        'label' => 'Measure directory size',
        'status' => 'dispatched',
    ]);

    $processor = app(\App\Services\GatewayInboundProcessor::class);

    // Step 1: the agent streams the `du -sb` output as it runs.
    $processor->handleInbound(json_encode([
        'type' => 'job_output',
        'job_id' => $job->uuid,
        'server_id' => $server->uuid,
        'payload' => ['stream' => 'stdout', 'data' => "20971520\t{$application->root_path}\n"],
    ]));

    // Step 2: the job finishes successfully.
    $processor->handleInbound(json_encode([
        'type' => 'job_result',
        'job_id' => $job->uuid,
        'server_id' => $server->uuid,
        'payload' => ['exit_code' => 0],
    ]));

    expect($application->refresh()->directory_size_bytes)->toBe(20971520);
});
