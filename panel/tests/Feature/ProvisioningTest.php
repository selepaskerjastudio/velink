<?php

use App\Models\AgentJob;
use App\Models\Server;
use App\Provisioning\ProvisioningCatalog;
use App\Services\ProvisionService;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('php steps include the PPA and one install per version', function () {
    $steps = app(ProvisioningCatalog::class)->steps('php', ['php_versions' => ['8.1', '8.3']]);

    expect($steps)->toHaveCount(3); // PPA + 2 installs
    expect($steps[0]['params']['command'])->toContain('ppa:ondrej/php');
    expect($steps[1]['params']['command'])->toContain('php8.1-fpm');
    expect($steps[2]['params']['command'])->toContain('php8.3-fpm');

    // Every step is a shell action with a timeout and a header echo.
    foreach ($steps as $step) {
        expect($step['type'])->toBe('shell');
        expect($step['params'])->toHaveKey('timeout');
        expect($step['params']['command'])->toStartWith('set -e');
    }
});

test('php install steps ship the default extension set per version', function () {
    $steps = app(ProvisioningCatalog::class)->steps('php', ['php_versions' => ['8.1', '8.3']]);

    // sqlite3 provides both ext-sqlite3 and ext-pdo_sqlite, required by common
    // Laravel tooling (PHPUnit in-memory db, Sushi, Filament log viewer, etc.).
    $expected = ['fpm', 'cli', 'common', 'mysql', 'pgsql', 'mbstring', 'xml', 'curl', 'zip', 'gd', 'bcmath', 'intl', 'sqlite3'];

    foreach ([$steps[1], $steps[2]] as $installStep) {
        $command = $installStep['params']['command'];
        foreach ($expected as $ext) {
            expect($command)->toContain($ext);
        }
    }
});

test('unsupported php version throws', function () {
    app(ProvisioningCatalog::class)->steps('php', ['php_versions' => ['5.6']]);
})->throws(InvalidArgumentException::class);

test('unknown component throws', function () {
    app(ProvisioningCatalog::class)->steps('frobnicate');
})->throws(InvalidArgumentException::class);

test('postgresql and mongodb recipes exist and are sh-safe', function () {
    $pg = app(ProvisioningCatalog::class)->steps('postgresql');
    $mongo = app(ProvisioningCatalog::class)->steps('mongodb');

    expect($pg[0]['params']['command'])->toContain('apt.postgresql.org');
    expect($mongo[0]['params']['command'])->toContain('repo.mongodb.org');
});

test('provision queues a sequential batch, dispatching only base first', function () {
    $server = Server::factory()->online()->create();

    // Capture every published envelope.
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);

        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    $jobs = app(ProvisionService::class)->provision($server, ['nginx'], ['php_versions' => ['8.3']]);

    // base (1 step) + nginx (1 step) = 2 jobs, all shell, one ordered batch.
    expect($jobs)->toHaveCount(2);
    expect(collect($jobs)->every(fn (AgentJob $j) => $j->type === 'shell'))->toBeTrue();
    expect($jobs[0]->batch_id)->not->toBeNull()
        ->and($jobs[1]->batch_id)->toBe($jobs[0]->batch_id)
        ->and($jobs[0]->batch_sequence)->toBe(0)
        ->and($jobs[1]->batch_sequence)->toBe(1);

    // Only the first step (base) is dispatched; nginx waits until base succeeds.
    expect($published)->toHaveCount(1);
    expect($published[0]['payload']['params']['command'])->toContain('software-properties-common');
    expect($jobs[0]->refresh()->status)->toBe(App\Models\AgentJob::STATUS_DISPATCHED)
        ->and($jobs[1]->refresh()->status)->toBe(App\Models\AgentJob::STATUS_PENDING);
});
