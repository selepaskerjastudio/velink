<?php

use App\Models\AuditLog;
use App\Models\FirewallRule;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function mockSecurityPublish(): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('publish')->andReturn(1);
    Redis::shouldReceive('connection')->andReturn($conn);
}

test('guests are redirected to the login page', function () {
    $server = Server::factory()->create();

    $this->get(route('security.index', $server))->assertRedirect('/login');
    $this->post(route('security.firewall.store', $server))->assertRedirect('/login');
});

test('the security page lists firewall rules and shows install status', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    FirewallRule::create(['server_id' => $server->id, 'protocol' => 'tcp', 'port' => 22, 'action' => 'allow', 'is_protected' => true]);
    FirewallRule::create(['server_id' => $server->id, 'protocol' => 'tcp', 'port' => 8080, 'action' => 'allow']);

    $this->actingAs($user)
        ->get(route('security.index', $server))
        ->assertInertia(fn ($page) => $page
            ->component('servers/security')
            ->has('firewallRules', 2)
            ->where('firewallRules.0.port', 22)     // SSH first
            ->where('firewallRules.0.is_protected', true)
            ->where('firewallRules.1.port', 8080)
            ->where('ufwInstalled', false)
            ->where('fail2banInstalled', false)
        );
});

test('a firewall rule can be added', function () {
    mockSecurityPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->post(route('security.firewall.store', $server), [
            'protocol' => 'tcp', 'port' => 3306, 'action' => 'allow', 'source' => null,
        ])
        ->assertRedirect(route('security.index', $server));

    expect($server->firewallRules()->where('port', 3306)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'firewall.rule_added')->where('server_id', $server->id)->exists())->toBeTrue();
});

test('adding a rule rejects invalid ports', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->from(route('security.index', $server))
        ->post(route('security.firewall.store', $server), [
            'protocol' => 'tcp', 'port' => 99999, 'action' => 'allow',
        ])
        ->assertSessionHasErrors('port');
});

test('adding a rule rejects invalid protocols', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->from(route('security.index', $server))
        ->post(route('security.firewall.store', $server), [
            'protocol' => 'icmp', 'port' => 80, 'action' => 'allow',
        ])
        ->assertSessionHasErrors('protocol');
});

test('adding a duplicate rule is rejected', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    FirewallRule::create(['server_id' => $server->id, 'protocol' => 'tcp', 'port' => 8080, 'action' => 'allow']);

    $this->actingAs($user)
        ->from(route('security.index', $server))
        ->post(route('security.firewall.store', $server), [
            'protocol' => 'tcp', 'port' => 8080, 'action' => 'allow', 'source' => null,
        ])
        ->assertSessionHasErrors('port');
});

test('a non-protected firewall rule can be deleted', function () {
    mockSecurityPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $rule = FirewallRule::create(['server_id' => $server->id, 'protocol' => 'tcp', 'port' => 8080, 'action' => 'allow']);

    $this->actingAs($user)
        ->delete(route('security.firewall.destroy', [$server, $rule]))
        ->assertRedirect(route('security.index', $server));

    expect(FirewallRule::find($rule->id))->toBeNull();
});

test('protected firewall rules cannot be deleted', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();
    $rule = FirewallRule::create(['server_id' => $server->id, 'protocol' => 'tcp', 'port' => 22, 'action' => 'allow', 'is_protected' => true]);

    $this->actingAs($user)
        ->delete(route('security.firewall.destroy', [$server, $rule]))
        ->assertForbidden();

    expect(FirewallRule::find($rule->id))->not->toBeNull();
});

test('ban ip dispatches fail2ban-client ban command', function () {
    mockSecurityPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->post(route('security.fail2ban.ban', $server), ['ip' => '203.0.113.5'])
        ->assertRedirect(route('security.index', $server));

    $command = \App\Models\AgentJob::where('server_id', $server->id)
        ->where('type', 'shell')->latest('id')->first()->payload['command'];
    expect($command)->toContain('fail2ban-client set sshd banip')
        ->and($command)->toContain('203.0.113.5');
    expect(AuditLog::where('action', 'fail2ban.banned')->exists())->toBeTrue();
});

test('unban ip dispatches fail2ban-client unban command', function () {
    mockSecurityPublish();

    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->delete(route('security.fail2ban.unban', [$server, '203.0.113.5']))
        ->assertRedirect(route('security.index', $server));

    $command = \App\Models\AgentJob::where('server_id', $server->id)
        ->where('type', 'shell')->latest('id')->first()->payload['command'];
    expect($command)->toContain('fail2ban-client set sshd unbanip')
        ->and($command)->toContain('203.0.113.5');
});

test('ban ip rejects non-IP input', function () {
    $user = User::factory()->create();
    $server = Server::factory()->online()->create();

    $this->actingAs($user)
        ->from(route('security.index', $server))
        ->post(route('security.fail2ban.ban', $server), ['ip' => 'not-an-ip'])
        ->assertSessionHasErrors('ip');
});
