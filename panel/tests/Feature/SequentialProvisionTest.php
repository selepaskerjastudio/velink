<?php

use App\Events\AgentJobUpdated;
use App\Events\ServerPresenceUpdated;
use App\Models\AgentJob;
use App\Models\Server;
use App\Models\Service;
use App\Services\GatewayInboundProcessor;
use App\Services\JobDispatcher;
use App\Services\ServiceManager;
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

/**
 * A phased batch: phase 0 = base; phase 1 = two parallel installs (nginx,
 * redis); phase 2 = composer (depends on the earlier phase).
 */
function phasedSteps(): array
{
    return [
        ['name' => 'Install base packages', 'type' => 'shell', 'phase' => 0, 'params' => ['command' => 'echo base']],
        ['name' => 'Install nginx', 'type' => 'shell', 'phase' => 1, 'params' => ['command' => 'echo nginx']],
        ['name' => 'Install Redis', 'type' => 'shell', 'phase' => 1, 'params' => ['command' => 'echo redis']],
        ['name' => 'Install composer', 'type' => 'shell', 'phase' => 2, 'params' => ['command' => 'echo composer']],
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

test('queueBatch dispatches only the first phase and leaves later phases pending', function () {
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $jobs = app(JobDispatcher::class)->queueBatch($server, phasedSteps());

    expect($jobs)->toHaveCount(4);

    // Only the phase-0 job (base) is dispatched.
    expect($jobs[0]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED)
        ->and($jobs[1]->refresh()->status)->toBe(AgentJob::STATUS_PENDING)
        ->and($jobs[2]->refresh()->status)->toBe(AgentJob::STATUS_PENDING)
        ->and($jobs[3]->refresh()->status)->toBe(AgentJob::STATUS_PENDING);

    expect($sink)->toHaveCount(1)
        ->and($sink[0]['job_id'])->toBe($jobs[0]->uuid);
});

test('finishing a phase dispatches every job in the next phase in parallel', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $jobs = app(JobDispatcher::class)->queueBatch($server, phasedSteps());

    completeJob($jobs[0]); // base done → phase 1 (nginx + redis) dispatched together

    expect($jobs[1]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED)
        ->and($jobs[2]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED)
        ->and($jobs[3]->refresh()->status)->toBe(AgentJob::STATUS_PENDING); // composer waits
});

test('the next phase waits until every job in the current phase has finished', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $jobs = app(JobDispatcher::class)->queueBatch($server, phasedSteps());

    completeJob($jobs[0]); // phase 1 dispatched
    completeJob($jobs[1]); // nginx done, redis still running

    expect($jobs[3]->refresh()->status)->toBe(AgentJob::STATUS_PENDING);

    completeJob($jobs[2]); // redis done → phase 1 complete → composer dispatched

    expect($jobs[3]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED);
});

test('a phase that fails entirely halts the batch', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $jobs = app(JobDispatcher::class)->queueBatch($server, phasedSteps());

    completeJob($jobs[0]);
    completeJob($jobs[1], exit: 1, error: 'boom'); // nginx fails
    completeJob($jobs[2], exit: 1, error: 'boom'); // redis fails → whole phase failed

    // composer (downstream) is skipped, not left pending forever.
    expect($jobs[3]->refresh()->status)->toBe(AgentJob::STATUS_FAILED)
        ->and($jobs[3]->refresh()->error)->toContain('halted');
});

test('a phase with a partial failure still advances', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $jobs = app(JobDispatcher::class)->queueBatch($server, phasedSteps());

    completeJob($jobs[0]);
    completeJob($jobs[1], exit: 1, error: 'boom'); // nginx fails
    completeJob($jobs[2]);                          // redis succeeds → phase had success

    expect($jobs[3]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED);
});

test('batches on different servers advance independently (per server)', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $a = Server::factory()->create();
    $b = Server::factory()->create();

    $ja = app(JobDispatcher::class)->queueBatch($a, phasedSteps());
    $jb = app(JobDispatcher::class)->queueBatch($b, phasedSteps());

    // Completing server A's base advances ONLY server A into phase 1.
    completeJob($ja[0]);

    expect($ja[1]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED)
        ->and($jb[1]->refresh()->status)->toBe(AgentJob::STATUS_PENDING)
        ->and($jb[0]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED);
});

test('presence online re-dispatches jobs stuck dispatched past the threshold', function () {
    Event::fake([ServerPresenceUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    Service::create(['server_id' => $server->id, 'type' => 'systemd', 'name' => 'nginx', 'status' => 'running']);

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
    Service::create(['server_id' => $server->id, 'type' => 'systemd', 'name' => 'nginx', 'status' => 'running']);

    $fresh = AgentJob::factory()->for($server)->create(['status' => AgentJob::STATUS_DISPATCHED]);
    $fresh->forceFill(['dispatched_at' => now()->subSeconds(5)])->save();

    app(GatewayInboundProcessor::class)->handlePresence(json_encode([
        'server_id' => $server->uuid,
        'status' => GatewayProtocol::STATUS_ONLINE,
    ]));

    expect($sink)->toBeEmpty();
});

test('seedForServer marks services as waiting', function () {
    $server = Server::factory()->create();
    app(ServiceManager::class)->seedForServer($server, ['nginx'], []);

    expect($server->services()->where('name', 'nginx')->first()->status)->toBe(ServiceManager::STATUS_WAITING);
});

test('a batch drives service status waiting → installing (on dispatch) → running', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    app(ServiceManager::class)->seedForServer($server, ['nginx'], []);
    expect($server->services()->where('name', 'nginx')->first()->status)->toBe(ServiceManager::STATUS_WAITING);

    $jobs = app(JobDispatcher::class)->queueBatch($server, [
        ['name' => 'Install base packages', 'type' => 'shell', 'phase' => 0, 'params' => ['command' => 'echo base']],
        ['name' => 'Install nginx', 'type' => 'shell', 'phase' => 1, 'params' => ['command' => 'echo nginx']],
    ]);

    completeJob($jobs[0]); // base done → nginx dispatched

    // Installing the moment it's dispatched (before any output).
    expect($jobs[1]->refresh()->status)->toBe(AgentJob::STATUS_DISPATCHED)
        ->and($server->services()->where('name', 'nginx')->first()->status)->toBe(ServiceManager::STATUS_INSTALLING);

    completeJob($jobs[1]); // nginx done → running
    expect($server->services()->where('name', 'nginx')->first()->status)->toBe(ServiceManager::STATUS_RUNNING);
});

test('a failed install marks its service and the halted ones not installed', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    app(ServiceManager::class)->seedForServer($server, ['nginx', 'mariadb'], []);

    // phase 1 = nginx (its own phase), phase 2 = mariadb downstream.
    $jobs = app(JobDispatcher::class)->queueBatch($server, [
        ['name' => 'Install nginx', 'type' => 'shell', 'phase' => 1, 'params' => ['command' => 'x']],
        ['name' => 'Install MariaDB', 'type' => 'shell', 'phase' => 2, 'params' => ['command' => 'x']],
    ]);

    completeJob($jobs[0], exit: 1, error: 'boom'); // nginx phase fails entirely → halt

    expect($server->services()->where('name', 'nginx')->first()->status)->toBe(ServiceManager::STATUS_NOT_INSTALLED)
        ->and($server->services()->where('name', 'mariadb')->first()->status)->toBe(ServiceManager::STATUS_NOT_INSTALLED);
});

test('control restart shows restarting then running when the restart job finishes', function () {
    Event::fake([AgentJobUpdated::class]);
    $sink = [];
    capturePublishedEnvelopes($sink);

    $server = Server::factory()->create();
    $svc = Service::create([
        'server_id' => $server->id, 'type' => 'systemd', 'name' => 'nginx',
        'status' => ServiceManager::STATUS_RUNNING, 'config' => ['label' => 'NGINX'],
    ]);

    $job = app(ServiceManager::class)->control($svc, 'restart');
    expect($svc->refresh()->status)->toBe(ServiceManager::STATUS_RESTARTING);

    completeJob($job);
    expect($svc->refresh()->status)->toBe(ServiceManager::STATUS_RUNNING);
});
