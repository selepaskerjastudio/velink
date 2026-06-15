<?php

use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Facades\Redis;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function mockGatewayForGitLabWebhook(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('gitlab webhook triggers a deploy when token and branch match', function () {
    mockGatewayForGitLabWebhook();

    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    $payload = ['ref' => 'refs/heads/main', 'repository' => ['name' => 'widgets']];

    $response = $this->postJson(route('webhooks.gitlab', $app), $payload, [
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Token' => 'testsecret1234567890123456789012345678',
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk()->assertJson(['status' => 'dispatched']);
    $this->assertDatabaseHas('deployments', [
        'application_id' => $app->id,
        'triggered_by' => 'webhook',
        'branch' => 'main',
    ]);
});

test('gitlab webhook ignores push to non-target branch', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    $payload = ['ref' => 'refs/heads/feature/foo'];

    $response = $this->postJson(route('webhooks.gitlab', $app), $payload, [
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Token' => 'testsecret1234567890123456789012345678',
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk()->assertJson(['status' => 'branch_mismatch']);
    $this->assertDatabaseMissing('deployments', ['application_id' => $app->id]);
});

test('gitlab webhook rejects request with wrong token', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    $payload = ['ref' => 'refs/heads/main'];

    $response = $this->postJson(route('webhooks.gitlab', $app), $payload, [
        'X-Gitlab-Event' => 'Push Hook',
        'X-Gitlab-Token' => 'wrongtoken',
        'Content-Type' => 'application/json',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('deployments', ['application_id' => $app->id]);
});

test('gitlab webhook ignores non-push events', function () {
    $server = Server::factory()->online()->create();
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => 'testsecret1234567890123456789012345678',
    ]);

    $payload = ['object_kind' => 'merge_request'];

    $response = $this->postJson(route('webhooks.gitlab', $app), $payload, [
        'X-Gitlab-Event' => 'Merge Request Hook',
        'X-Gitlab-Token' => 'testsecret1234567890123456789012345678',
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk()->assertJson(['status' => 'ignored']);
    $this->assertDatabaseMissing('deployments', ['application_id' => $app->id]);
});
