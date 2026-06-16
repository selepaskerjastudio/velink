<?php

use App\Models\AgentJob;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create an AgentJob with explicit status + an age. $minutesAgo backdates both
 * dispatched_at and created_at so the command's age filter sees it as old.
 */
function stuckJob(Server $server, string $status, int $minutesAgo): AgentJob
{
    $when = now()->subMinutes($minutesAgo);

    return AgentJob::factory()->for($server)->create([
        'status' => $status,
        'dispatched_at' => $when,
    ])->forceFill(['created_at' => $when])->fresh();
}

test('it deletes stuck dispatched and pending jobs older than the threshold but keeps the rest', function () {
    $server = Server::factory()->create();

    $oldDispatched = stuckJob($server, AgentJob::STATUS_DISPATCHED, 30);
    $oldPending = stuckJob($server, AgentJob::STATUS_PENDING, 30);

    $recentDispatched = stuckJob($server, AgentJob::STATUS_DISPATCHED, 1);
    $running = stuckJob($server, AgentJob::STATUS_RUNNING, 30);
    $succeeded = stuckJob($server, AgentJob::STATUS_SUCCEEDED, 30);
    $failed = stuckJob($server, AgentJob::STATUS_FAILED, 30);

    $this->artisan('jobs:purge-stuck', ['--force' => true, '--older-than' => 5])
        ->assertSuccessful();

    expect(AgentJob::whereKey($oldDispatched->id)->exists())->toBeFalse()
        ->and(AgentJob::whereKey($oldPending->id)->exists())->toBeFalse();

    expect(AgentJob::whereKey($recentDispatched->id)->exists())->toBeTrue()
        ->and(AgentJob::whereKey($running->id)->exists())->toBeTrue()
        ->and(AgentJob::whereKey($succeeded->id)->exists())->toBeTrue()
        ->and(AgentJob::whereKey($failed->id)->exists())->toBeTrue();
});

test('--server scopes deletion to one server only', function () {
    $serverA = Server::factory()->create();
    $serverB = Server::factory()->create();

    $jobA = stuckJob($serverA, AgentJob::STATUS_DISPATCHED, 30);
    $jobB = stuckJob($serverB, AgentJob::STATUS_DISPATCHED, 30);

    $this->artisan('jobs:purge-stuck', [
        '--force' => true,
        '--older-than' => 5,
        '--server' => $serverA->uuid,
    ])->assertSuccessful();

    expect(AgentJob::whereKey($jobA->id)->exists())->toBeFalse()
        ->and(AgentJob::whereKey($jobB->id)->exists())->toBeTrue();
});

test('--older-than=0 deletes all dispatched regardless of age', function () {
    $server = Server::factory()->create();

    $old = stuckJob($server, AgentJob::STATUS_DISPATCHED, 30);
    $recent = stuckJob($server, AgentJob::STATUS_DISPATCHED, 0);
    $pending = stuckJob($server, AgentJob::STATUS_PENDING, 30);

    $this->artisan('jobs:purge-stuck', [
        '--force' => true,
        '--status' => 'dispatched',
        '--older-than' => 0,
    ])->assertSuccessful();

    expect(AgentJob::whereKey($old->id)->exists())->toBeFalse()
        ->and(AgentJob::whereKey($recent->id)->exists())->toBeFalse();

    // Pending was not in --status, so it survives.
    expect(AgentJob::whereKey($pending->id)->exists())->toBeTrue();
});

test('an unknown server uuid fails without deleting anything', function () {
    $server = Server::factory()->create();
    $job = stuckJob($server, AgentJob::STATUS_DISPATCHED, 30);

    $this->artisan('jobs:purge-stuck', [
        '--force' => true,
        '--server' => 'does-not-exist',
    ])->assertFailed();

    expect(AgentJob::whereKey($job->id)->exists())->toBeTrue();
});

test('it reports zero matches and succeeds when nothing is stuck', function () {
    $server = Server::factory()->create();
    stuckJob($server, AgentJob::STATUS_SUCCEEDED, 30);

    $this->artisan('jobs:purge-stuck', ['--force' => true, '--older-than' => 5])
        ->assertSuccessful();
});
