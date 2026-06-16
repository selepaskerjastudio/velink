<?php

use App\Events\AgentJobUpdated;
use App\Events\ServerPresenceUpdated;
use App\Models\AgentJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Service;
use App\Services\GatewayInboundProcessor;
use App\Services\ServiceManager;
use App\Support\GatewayProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function mockGatewayRedis(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

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
    mockGatewayRedis();
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

test('first connect auto-provisions core services and seeds records', function () {
    Event::fake([ServerPresenceUpdated::class]);
    mockGatewayRedis();

    $s = Server::factory()->create();

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => $s->uuid,
        'status' => GatewayProtocol::STATUS_ONLINE,
    ]));

    // Several provision jobs should be dispatched (base + nginx + supervisor + redis + php steps).
    expect($s->agentJobs()->count())->toBeGreaterThan(0);
    expect($s->agentJobs()->where('label', ServiceManager::PROBE_LABEL)->count())->toBe(0);

    // Service records are seeded immediately so the dashboard shows them.
    $names = $s->services()->where('type', 'systemd')->pluck('name')->sort()->values()->all();
    expect($names)->toContain('nginx', 'supervisor', 'redis-server', 'php8.3-fpm');
});

test('reconnect without services dispatches probe instead of re-provisioning', function () {
    Event::fake([ServerPresenceUpdated::class]);
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($ch, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $s = Server::factory()->create();
    // Simulate a server that already had jobs (was provisioned before) but lost service records.
    AgentJob::factory()->for($s)->create(['label' => 'Install nginx']);

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => $s->uuid,
        'status' => GatewayProtocol::STATUS_ONLINE,
    ]));

    $probeJob = $s->agentJobs()->where('label', ServiceManager::PROBE_LABEL)->first();
    expect($probeJob)->not->toBeNull();
    expect($probeJob->payload['command'])->toContain('systemctl cat');
});

test('presence online does nothing extra when services already exist', function () {
    Event::fake([ServerPresenceUpdated::class]);
    mockGatewayRedis();
    $s = Server::factory()->create();
    Service::create(['server_id' => $s->id, 'type' => 'systemd', 'name' => 'nginx', 'status' => 'active']);

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => $s->uuid,
        'status' => GatewayProtocol::STATUS_ONLINE,
    ]));

    expect($s->agentJobs()->where('label', ServiceManager::PROBE_LABEL)->count())->toBe(0);
    expect($s->agentJobs()->count())->toBe(0);
});

test('probe job result seeds services from output', function () {
    Event::fake([AgentJobUpdated::class]);
    $s = Server::factory()->create();
    $j = AgentJob::factory()->for($s)->running()->create(['label' => ServiceManager::PROBE_LABEL]);
    $j->forceFill(['output' => "nginx=active\nredis-server=inactive\nphp8.3-fpm=active\n"])->save();

    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_JOB_RESULT,
        'job_id' => $j->uuid,
        'payload' => ['exit_code' => 0],
    ]));

    $services = $s->services()->where('type', 'systemd')->orderBy('name')->pluck('name')->all();
    expect($services)->toEqual(['nginx', 'php8.3-fpm', 'redis-server']);

    $nginx = $s->services()->where('name', 'nginx')->first();
    expect($nginx->status)->toBe('running'); // active → running
    expect($nginx->config['label'])->toBe('NGINX');

    $redis = $s->services()->where('name', 'redis-server')->first();
    expect($redis->status)->toBe('stopped'); // inactive → stopped

    $php = $s->services()->where('name', 'php8.3-fpm')->first();
    expect($php->config['label'])->toBe('PHP 8.3 FPM');
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

test('metrics envelope inserts a ServerMetric record', function () {
    $s = Server::factory()->create();

    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_METRICS,
        'server_id' => $s->uuid,
        'payload' => [
            'cpu_percent' => 12.34,
            'mem_total' => 8_000_000_000,
            'mem_used' => 4_000_000_000,
            'disk_total' => 100_000_000_000,
            'disk_used' => 50_000_000_000,
            'load1' => 0.75,
        ],
    ]));

    expect(ServerMetric::where('server_id', $s->id)->count())->toBe(1);

    $metric = ServerMetric::where('server_id', $s->id)->first();
    expect($metric->cpu_percent)->toBe(12.34)
        ->and($metric->mem_total)->toBe(8_000_000_000)
        ->and($metric->load1)->toBe(0.75)
        ->and($metric->recorded_at)->not->toBeNull();
});

test('metrics envelope with unknown server uuid is ignored', function () {
    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_METRICS,
        'server_id' => (string) Str::uuid(),
        'payload' => ['cpu_percent' => 5.0],
    ]));

    expect(ServerMetric::count())->toBe(0);
});

test('an application becomes active once all its provisioning jobs succeed', function () {
    mockGatewayRedis();
    Event::fake([AgentJobUpdated::class]);
    $server = Server::factory()->create();
    $application = Application::factory()->for($server)->create(['status' => 'provisioning']);

    $jobA = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);
    $jobB = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);

    $complete = fn (AgentJob $j) => app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_JOB_RESULT,
        'job_id' => $j->uuid,
        'server_id' => $server->id,
        'payload' => ['exit_code' => 0],
    ]));

    // Still provisioning while one job is outstanding.
    $complete($jobA);
    expect($application->refresh()->status)->toBe('provisioning');

    // Last job done → active.
    $complete($jobB);
    expect($application->refresh()->status)->toBe('active');
});

test('an application is marked failed when a provisioning job fails', function () {
    mockGatewayRedis();
    Event::fake([AgentJobUpdated::class]);
    $server = Server::factory()->create();
    $application = Application::factory()->for($server)->create(['status' => 'provisioning']);

    $job = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);

    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_JOB_RESULT,
        'job_id' => $job->uuid,
        'server_id' => $server->id,
        'payload' => ['exit_code' => 1, 'error' => 'boom'],
    ]));

    expect($application->refresh()->status)->toBe('failed');
});

test('a later job on an active application does not reopen its lifecycle', function () {
    mockGatewayRedis();
    Event::fake([AgentJobUpdated::class]);
    $server = Server::factory()->create();
    $application = Application::factory()->for($server)->create(['status' => 'active']);

    $job = AgentJob::factory()->for($server)->running()->create(['application_id' => $application->id]);

    app(GatewayInboundProcessor::class)->handleInbound(json_encode([
        'type' => GatewayProtocol::TYPE_JOB_RESULT,
        'job_id' => $job->uuid,
        'server_id' => $server->id,
        'payload' => ['exit_code' => 1],
    ]));

    expect($application->refresh()->status)->toBe('active');
});
