<?php

use App\Models\AgentJob;
use App\Models\Server;
use App\Services\JobDispatcher;
use App\Support\GatewayProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

test('dispatch creates a job and publishes the envelope', function () {
    $server = Server::factory()->online()->create();

    $captured = null;
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')
        ->once()
        ->with(GatewayProtocol::CHANNEL_DISPATCH, Mockery::on(function ($json) use (&$captured) {
            $captured = json_decode($json, true);

            return true;
        }));
    Redis::shouldReceive('connection')->with('gateway')->andReturn($conn);

    $job = app(JobDispatcher::class)->dispatch($server, 'shell', ['command' => 'echo hi']);

    expect($job->status)->toBe(AgentJob::STATUS_DISPATCHED)
        ->and($job->dispatched_at)->not->toBeNull()
        ->and($job->uuid)->not->toBeEmpty();

    expect($captured)->toMatchArray([
        'type' => GatewayProtocol::TYPE_JOB,
        'job_id' => $job->uuid,
        'server_id' => $server->uuid,
    ]);
    expect($captured['payload'])->toMatchArray([
        'action' => 'shell',
        'params' => ['command' => 'echo hi'],
    ]);
    expect($captured['ts'])->toBeInt();
});

test('buildEnvelope uses the server uuid as the wire server_id', function () {
    $server = Server::factory()->online()->create();
    $job = AgentJob::factory()->for($server)->create();

    $envelope = app(JobDispatcher::class)->buildEnvelope($job);

    expect($envelope['server_id'])->toBe($job->server->uuid)
        ->and($envelope['server_id'])->toBe($server->uuid);
});

test('payload is encrypted at rest', function () {
    $server = Server::factory()->create();
    Redis::shouldReceive('connection')->andReturn(
        tap(Mockery::mock(), fn ($m) => $m->shouldReceive('publish'))
    );

    $job = app(JobDispatcher::class)->dispatch($server, 'write_file', ['path' => '/etc/secret', 'content' => 's3cret']);

    $raw = DB::table('agent_jobs')->where('id', $job->id)->value('payload');
    expect($raw)->not->toContain('s3cret');
    expect($job->fresh()->payload)->toMatchArray(['path' => '/etc/secret', 'content' => 's3cret']);
});
