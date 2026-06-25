<?php

use App\Models\AgentJob;
use App\Models\FirewallRule;
use App\Models\Server;
use App\Models\User;
use App\Services\FirewallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockFirewallPublish(): array
{
    $published = [];
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$published) {
        $published[] = json_decode($json, true);
        return 1;
    });
    Redis::shouldReceive('connection')->andReturn($conn);

    return $published;
}

test('ensure_defaults seeds SSH HTTP HTTPS when no rules exist', function () {
    $server = Server::factory()->online()->create();

    app(FirewallService::class)->ensureDefaults($server);

    $rules = $server->firewallRules()->orderBy('port')->get();
    expect($rules)->toHaveCount(3)
        ->and($rules[0]->port)->toBe(22)
        ->and($rules[0]->is_protected)->toBeTrue()
        ->and($rules[1]->port)->toBe(80)
        ->and($rules[2]->port)->toBe(443);
});

test('ensure_defaults is idempotent — does not re-seed', function () {
    $server = Server::factory()->online()->create();
    FirewallRule::create(['server_id' => $server->id, 'protocol' => 'tcp', 'port' => 22, 'action' => 'allow', 'is_protected' => true]);

    app(FirewallService::class)->ensureDefaults($server);

    expect($server->firewallRules()->count())->toBe(1);
});

test('sync_rules dispatches a ufw reset + re-apply + enable sequence', function () {
    $published = mockFirewallPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    app(FirewallService::class)->syncRules($server, $user->id);

    $command = AgentJob::where('server_id', $server->id)
        ->where('type', 'shell')
        ->latest('id')->first()->payload['command'];

    expect($command)->toContain('ufw --force reset')
        ->and($command)->toContain('ufw --force enable')
        // Defaults were auto-seeded — 22 appears.
        ->and($command)->toContain('22/tcp')
        ->and($command)->toContain('80/tcp')
        ->and($command)->toContain('443/tcp');
});

test('sync_rules applies custom rules from the DB', function () {
    $published = mockFirewallPublish();

    $server = Server::factory()->online()->create();
    FirewallRule::create(['server_id' => $server->id, 'protocol' => 'udp', 'port' => 51820, 'action' => 'allow', 'source' => '10.0.0.0/8']);

    app(FirewallService::class)->syncRules($server);

    $command = AgentJob::where('server_id', $server->id)
        ->where('type', 'shell')->latest('id')->first()->payload['command'];

    expect($command)->toContain('51820/udp')
        ->and($command)->toContain('from 10.0.0.0/8');
});

test('add_rule creates a row and syncs UFW', function () {
    $published = mockFirewallPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    app(FirewallService::class)->addRule($server, [
        'protocol' => 'tcp', 'port' => 3306, 'action' => 'allow', 'source' => null,
    ], $user->id);

    expect($server->firewallRules()->where('port', 3306)->exists())->toBeTrue();

    $command = AgentJob::where('server_id', $server->id)
        ->where('type', 'shell')->latest('id')->first()->payload['command'];
    expect($command)->toContain('3306/tcp');
});

test('delete_rule removes the row and syncs UFW', function () {
    $published = mockFirewallPublish();

    $server = Server::factory()->online()->create();
    $rule = FirewallRule::create(['server_id' => $server->id, 'protocol' => 'tcp', 'port' => 8080, 'action' => 'allow']);

    app(FirewallService::class)->deleteRule($rule);

    expect(FirewallRule::find($rule->id))->toBeNull();
    $command = AgentJob::where('server_id', $server->id)
        ->where('type', 'shell')->latest('id')->first()->payload['command'];
    // 8080 should NOT appear after deletion.
    expect($command)->not->toContain('8080');
});

test('ssh rule is always seeded first in the sync command', function () {
    $published = mockFirewallPublish();

    $server = Server::factory()->online()->create();

    app(FirewallService::class)->syncRules($server);

    $command = AgentJob::where('server_id', $server->id)
        ->where('type', 'shell')->latest('id')->first()->payload['command'];

    // The first ufw allow line must be port 22.
    $allowLines = collect(explode("\n", $command))->filter(fn ($l) => str_starts_with(trim($l), 'ufw --force allow'));
    expect(trim($allowLines->first()))->toContain('22/tcp');
});
