<?php

use App\Models\AgentJob;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\DeploymentService;
use Illuminate\Support\Facades\Redis;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function mockGatewayForWebhook(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

function githubSignature(string $secret, string $payload): string
{
    return 'sha256='.hash_hmac('sha256', $payload, $secret);
}

/**
 * Post a webhook with the EXACT raw body string so the HMAC matches what the
 * controller sees via $request->getContent(). Using postJson would re-encode
 * the body and could change the bytes, breaking the signature.
 */
function postGithubWebhook(Application $app, string $rawBody, array $headers = []): \Illuminate\Testing\TestResponse
{
    return test()->call('POST', route('webhooks.github', $app), [], [], [], array_merge([
        'HTTP_X-GitHub-Event' => 'push',
        'HTTP_X-Hub-Signature-256' => githubSignature($app->webhook_secret, $rawBody),
        'CONTENT_TYPE' => 'application/json',
    ], $headers), $rawBody);
}

test('webhook triggers a deploy when signature and branch match', function () {
    mockGatewayForWebhook();

    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    $payload = json_encode(['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widgets']]);

    postGithubWebhook($app, $payload)
        ->assertOk()->assertJson(['status' => 'dispatched']);

    // A deployment row was created...
    $this->assertDatabaseHas('deployments', [
        'application_id' => $app->id,
        'triggered_by' => 'webhook',
        'branch' => 'main',
    ]);

    // ...AND an AgentJob was actually dispatched with the correct deploy command.
    // Payload is encrypted:array — hydrate the model to read the decoded command.
    $job = AgentJob::where('application_id', $app->id)->where('type', 'shell')->latest('id')->first();
    expect($job)->not->toBeNull()
        ->and($job->label)->toContain('Deploy')
        ->and($job->payload['command'])->toContain('sudo -u');
});

test('webhook ignores push to non-target branch', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    $payload = json_encode(['ref' => 'refs/heads/feature/foo']);

    postGithubWebhook($app, $payload)
        ->assertOk()->assertJson(['status' => 'branch_mismatch']);

    $this->assertDatabaseMissing('deployments', ['application_id' => $app->id]);
});

test('webhook rejects request with invalid signature', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    $payload = json_encode(['ref' => 'refs/heads/main']);

    $this->call('POST', route('webhooks.github', $app), [], [], [], [
        'HTTP_X-GitHub-Event' => 'push',
        'HTTP_X-Hub-Signature-256' => 'sha256=invalidsignature',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertForbidden();

    $this->assertDatabaseMissing('deployments', ['application_id' => $app->id]);
});

test('webhook ignores non-push events', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    $payload = json_encode(['action' => 'opened']);

    postGithubWebhook($app, $payload, ['HTTP_X-GitHub-Event' => 'pull_request'])
        ->assertOk()->assertJson(['status' => 'ignored']);
});

test('concurrent deploys are rejected by the concurrency guard', function () {
    mockGatewayForWebhook();

    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    // Seed a running deployment to simulate one already in progress.
    Deployment::create([
        'application_id' => $app->id,
        'branch' => 'main',
        'mode' => 'inplace',
        'status' => 'running',
        'triggered_by' => 'manual',
    ]);

    $payload = json_encode(['ref' => 'refs/heads/main']);

    postGithubWebhook($app, $payload)
        ->assertOk()->assertJson(['status' => 'skipped_concurrent']);

    // The second deploy was recorded as failed, not dispatched.
    $skipped = Deployment::where('application_id', $app->id)->where('triggered_by', 'webhook')->first();
    expect($skipped)->not->toBeNull()
        ->and($skipped->status)->toBe('failed')
        ->and($skipped->log)->toContain('already running');
});
