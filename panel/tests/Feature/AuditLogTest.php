<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

function mockRedisForAuditLog(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('server creation logs server.created', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->post('/servers', [
        'name' => 'audit-test-server',
        'hostname' => 'audit-test.internal',
        'public_ip' => '203.0.113.1',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'server.created',
    ]);

    $log = AuditLog::where('action', 'server.created')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('audit-test-server');
    expect($log->properties['server_uuid'])->not->toBeNull();
});

test('deploy trigger logs application.deployed', function () {
    mockRedisForAuditLog();

    $user = User::factory()->create();
    $this->actingAs($user);
    $server = Server::factory()->online()->create();
    $application = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/myapp',
        'branch' => 'main',
    ]);

    $response = $this->post(route('applications.deployments.store', $application));

    $response->assertRedirect(route('applications.show', $application));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'application.deployed',
    ]);

    $log = AuditLog::where('action', 'application.deployed')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('manual');
    expect($log->user_id)->toBe($user->id);
    expect($log->server_id)->toBe($server->id);
});

test('webhook deploy logs application.deployed with webhook trigger', function () {
    mockRedisForAuditLog();

    $server = Server::factory()->online()->create();
    $webhookSecret = 'testsecret1234567890123456789012345678';
    $app = Application::factory()->create([
        'server_id' => $server->id,
        'repository' => 'acme/widgets',
        'branch' => 'main',
        'webhook_secret' => $webhookSecret,
    ]);

    $payload = json_encode(['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/widgets']]);
    $signature = 'sha256='.hash_hmac('sha256', $payload, $webhookSecret);

    $response = $this->postJson(route('webhooks.github', $app), json_decode($payload, true), [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => $signature,
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk()->assertJson(['status' => 'dispatched']);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'application.deployed',
    ]);

    $log = AuditLog::where('action', 'application.deployed')->first();
    expect($log)->not->toBeNull();
    expect($log->description)->toContain('webhook');
    expect($log->properties['triggered_by'])->toBe('webhook');
    expect($log->user_id)->toBeNull();
});

test('audit log index page returns 200 for authenticated user', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/audit-logs')->assertOk();
});

test('audit log index is inaccessible to guests', function () {
    $this->get('/audit-logs')->assertRedirect('/login');
});
