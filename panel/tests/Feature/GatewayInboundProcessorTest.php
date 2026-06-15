<?php

use App\Events\AgentJobUpdated;
use App\Events\ServerPresenceUpdated;
use App\Models\AgentJob;
use App\Models\Server;
use App\Services\GatewayInboundProcessor;
use App\Support\GatewayProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('job output marks running, appends output, broadcasts', function () {
    Event::fake([AgentJobUpdated::class]);
    $s = Server::factory()->create();
    $j = AgentJob::factory()->for($s)->create();

    $payload = json_encode([
        'type' => GatewayProtocol::TYPE_JOB_OUTPUT,
        'job_id' => $j->uuid,
        'server_id' => $s->id,
        'payload' => ['stream' => 'stdout', 'data' => "hi\n"],
    ]);

    app(GatewayInboundProcessor::class)->handleInbound($payload);

    $j->refresh();
    expect($j->status)->toBe(AgentJob::STATUS_RUNNING)
        ->and($j->started_at)->not->toBeNull()
        ->and($j->output)->toBe("hi\n");

    Event::assertDispatched(AgentJobUpdated::class);
});

test('job result with exit 0 marks succeeded', function () {
    Event::fake([AgentJobUpdated::class]);
    $s = Server::factory()->create();
    $j = AgentJob::factory()->for($s)->running()->create();

    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_JOB_RESULT,
        'job_id' => $j->uuid,
        'server_id' => $s->id,
        'payload' => ['exit_code' => 0],
    ]));

    $j->refresh();
    expect($j->status)->toBe(AgentJob::STATUS_SUCCEEDED)
        ->and($j->exit_code)->toBe(0)
        ->and($j->finished_at)->not->toBeNull();
});

test('job result with non-zero exit marks failed with error', function () {
    Event::fake([AgentJobUpdated::class]);
    $s = Server::factory()->create();
    $j = AgentJob::factory()->for($s)->running()->create();

    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_JOB_RESULT,
        'job_id' => $j->uuid,
        'server_id' => $s->id,
        'payload' => ['exit_code' => 127, 'error' => 'command not found'],
    ]));

    $j->refresh();
    expect($j->status)->toBe(AgentJob::STATUS_FAILED)
        ->and($j->exit_code)->toBe(127)
        ->and($j->error)->toBe('command not found');
});

test('unknown job uuid is ignored', function () {
    Event::fake([AgentJobUpdated::class]);
    Server::factory()->create();

    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_JOB_RESULT,
        'job_id' => 'does-not-exist',
        'payload' => ['exit_code' => 0],
    ]));

    Event::assertNotDispatched(AgentJobUpdated::class);
});

test('presence online updates server and broadcasts', function () {
    Event::fake([ServerPresenceUpdated::class]);
    $s = Server::factory()->create();

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => $s->uuid,
        'status' => GatewayProtocol::STATUS_ONLINE,
        'agent_version' => '0.1.0',
    ]));

    $s->refresh();
    expect($s->status)->toBe('online')
        ->and($s->agent_version)->toBe('0.1.0')
        ->and($s->last_seen_at)->not->toBeNull();

    Event::assertDispatched(ServerPresenceUpdated::class);
});

test('presence offline updates server status', function () {
    Event::fake([ServerPresenceUpdated::class]);
    $s = Server::factory()->create();
    $s->update(['status' => 'online']);

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => $s->uuid,
        'status' => GatewayProtocol::STATUS_OFFLINE,
    ]));

    expect($s->refresh()->status)->toBe('offline');
});

test('presence event with unknown server uuid is ignored', function () {
    Event::fake([ServerPresenceUpdated::class]);
    Server::factory()->create();

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => (string) Str::uuid(),
        'status' => GatewayProtocol::STATUS_ONLINE,
    ]));

    Event::assertNotDispatched(ServerPresenceUpdated::class);
});
