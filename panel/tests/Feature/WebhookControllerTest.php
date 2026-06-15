<?php

use App\Models\Application;
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

    $response = $this->postJson(route('webhooks.github', $app), json_decode($payload, true), [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => githubSignature('testsecret1234567890123456789012345678', $payload),
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk()->assertJson(['status' => 'dispatched']);
    $this->assertDatabaseHas('deployments', [
        'application_id' => $app->id,
        'triggered_by' => 'webhook',
        'branch' => 'main',
    ]);
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

    $response = $this->postJson(route('webhooks.github', $app), json_decode($payload, true), [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => githubSignature('testsecret1234567890123456789012345678', $payload),
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk()->assertJson(['status' => 'branch_mismatch']);
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

    $response = $this->postJson(route('webhooks.github', $app), json_decode($payload, true), [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256=invalidsignature',
        'Content-Type' => 'application/json',
    ]);

    $response->assertForbidden();
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

    $response = $this->postJson(route('webhooks.github', $app), json_decode($payload, true), [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => githubSignature('testsecret1234567890123456789012345678', $payload),
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk()->assertJson(['status' => 'ignored']);
});
