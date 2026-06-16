<?php

use App\Events\AgentJobUpdated;
use App\Events\ServerPresenceUpdated;
use App\Models\AgentJob;
use App\Models\Server;
use App\Models\Service;
use App\Services\GatewayInboundProcessor;
use App\Services\JobDispatcher;
use App\Support\GatewayProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

/** Capture every envelope published to the gateway dispatch channel. */
function capturePublishedEnvelopes(array &$sink): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$sink) {
        $sink[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);
}

function threeSteps(): array
{
    return [
        ['name' => 'Step A', 'type' => 'shell', 'params' => ['command' => 'echo a']],
        ['name' => 'Step B', 'type' => 'shell', 'params' => ['command' => 'echo b']],
        ['name' => 'Step C', 'type' => 'shell', 'params' => ['command' => 'echo c']],
    ];
}

function completeJob(AgentJob $job, int $exit = 0, ?string $error = null): void
{
    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_JOB_RESULT,
        'job_id' => $job->uuid,
        'payload' => ['exit_code' => $exit, 'error' => $error],
    ]));
}

test('queueSequential dispatches only the first step and leaves the rest pending', function () {
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $jobs = app(JobDispatcher::class)->queueSequential($server, threeSteps());

    expect($jobs)->toHaveCount(3);

    $ordered = $server->agentJobs()->orderBy('batch_sequence')->get();
    expect($ordered[0]->status)->toBe(AgentJob::STATUS_DISPATCHED)
        ->and($ordered[1]->status)->toBe(AgentJob::STATUS_PENDING)
        ->and($ordered[2]->status)->toBe(AgentJob::STATUS_PENDING)
        ->and($ordered[0]->batch_id)->not->toBeNull()
        ->and($ordered[1]->batch_id)->toBe($ordered[0]->batch_id)
        ->and($ordered[2]->batch_sequence)->toBe(2);

    // Exactly one envelope (the first step) hit the wire.
    expect($sink)->toHaveCount(1)
        ->and($sink[0]['job_id'])->toBe($ordered[0]->uuid);
});

test('a succeeded batch step dispatches the next step only', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $jobs = app(JobDispatcher::class)->queueSequential($server, threeSteps());

    completeJob($jobs[0]);

    expect($jobs[1]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED)
        ->and($jobs[2]->refresh()->status)->toBe(AgentJob::STATUS_PENDING);

    completeJob($jobs[1]);

    expect($jobs[2]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED);
});

test('a failed batch step halts and marks the remaining steps', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $jobs = app(JobDispatcher::class)->queueSequential($server, threeSteps());

    completeJob($jobs[0], exit: 1, error: 'boom');

    expect($jobs[0]->refresh()->status)->toBe(AgentJob::STATUS_FAILED)
        ->and($jobs[1]->refresh()->status)->toBe(AgentJob::STATUS_FAILED)
        ->and($jobs[2]->refresh()->status)->toBe(AgentJob::STATUS_FAILED)
        ->and($jobs[1]->refresh()->error)->toContain('Skipped');

    // No further step was ever dispatched (only the first envelope went out).
    expect($sink)->toHaveCount(1);
});

test('presence online re-dispatches jobs stuck dispatched past the threshold', function () {
    Event::fake([ServerPresenceUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    // Services already exist so the provision/probe branches are skipped.
    Service::create(['server_id' => $server->id, 'type' => 'systemd', 'name' => 'nginx', 'status' => 'active']);

    $stuck = AgentJob::factory()->for($server)->create([
        'status' => AgentJob::STATUS_DISPATCHED,
        'label' => 'Install nginx',
    ]);
    $stuck->forceFill(['dispatched_at' => now()->subMinutes(5)])->save();

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => $server->uuid,
        'status' => GatewayProtocol::STATUS_ONLINE,
    ]));

    expect(collect($sink)->firstWhere('job_id', $stuck->uuid))->not->toBeNull();
});

test('presence online does not re-dispatch a freshly dispatched job', function () {
    Event::fake([ServerPresenceUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    Service::create(['server_id' => $server->id, 'type' => 'systemd', 'name' => 'nginx', 'status' => 'active']);

    $fresh = AgentJob::factory()->for($server)->create(['status' => AgentJob::STATUS_DISPATCHED]);
    $fresh->forceFill(['dispatched_at' => now()->subSeconds(5)])->save();

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => $server->uuid,
        'status' => GatewayProtocol::STATUS_ONLINE,
    ]));

    expect($sink)->toBeEmpty();
});
