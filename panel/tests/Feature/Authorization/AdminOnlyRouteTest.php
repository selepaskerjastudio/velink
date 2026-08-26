<?php

use App\Models\FirewallRule;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\SystemUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * Exhaustive matrix for the 20 routes RouteCoverageTest confirms carry the
 * 'admin' middleware. That test only checks the middleware is attached; this
 * one drives real requests to prove a member on the assigned server actually
 * gets a 403 from every one of them — not just the handful spot-checked in
 * MemberServerScopeTest.
 */
test('every admin-only route 403s a member assigned to the server', function () {
    $server = Server::factory()->create();
    $rule = FirewallRule::create(['server_id' => $server->id, 'protocol' => 'tcp', 'port' => 8080, 'action' => 'allow']);
    $systemUser = SystemUser::create(['server_id' => $server->id, 'username' => 'deployer', 'shell' => '/bin/bash']);

    // Owner only needs to satisfy the SshKey.user_id FK — the 'admin'
    // middleware rejects the request before the controller ever reads it.
    $keyOwner = actingAsMember($server);
    $sshKey = SshKey::create([
        'user_id' => $keyOwner->id,
        'name' => 'laptop',
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOUVskMQSrR6mA5dBMqTzhdi7ihbGagty1/+m/gV068b test@velink',
        'fingerprint' => 'SHA256:z0WuM5b4c/V3G3yZAHIhS6/U4uWoXL6XGy18Jw+0+hU',
        'type' => 'ssh-ed25519',
        'comment' => 'test@velink',
    ]);

    // The member who actually performs every request below.
    actingAsMember($server);

    $cases = [
        ['get', route('servers.create'), []],
        ['post', route('servers.store'), ['name' => 'x', 'hostname' => 'x.test']],
        ['get', route('servers.terminal', $server), []],
        ['post', route('servers.provision', $server), []],
        ['post', route('servers.restart', $server), []],
        ['post', route('servers.regenerate-token', $server), []],
        ['delete', route('servers.destroy', $server), []],
        ['get', route('system-users.index', $server), []],
        ['post', route('system-users.store', $server), ['username' => 'x', 'shell' => '/bin/bash']],
        ['patch', route('system-users.sudo', $systemUser), ['is_sudo' => true]],
        ['patch', route('system-users.shell', $systemUser), ['shell' => '/bin/bash']],
        ['delete', route('system-users.destroy', $systemUser), []],
        ['get', route('security.index', $server), []],
        ['post', route('security.firewall.store', $server), ['protocol' => 'tcp', 'port' => 9000, 'action' => 'allow']],
        ['delete', route('security.firewall.destroy', [$server, $rule]), []],
        ['post', route('security.fail2ban.install', $server), []],
        ['post', route('security.fail2ban.ban', $server), ['ip' => '1.2.3.4']],
        ['delete', route('security.fail2ban.unban', [$server, '1.2.3.4']), []],
        ['post', route('server.ssh-keys.deploy', [$server, $sshKey]), []],
        ['delete', route('server.ssh-keys.revoke', [$server, $sshKey]), []],
    ];

    $failures = [];

    foreach ($cases as [$method, $url, $payload]) {
        $status = $this->call($method, $url, $payload)->getStatusCode();

        if ($status !== 403) {
            $failures[] = "{$method} {$url} => {$status}";
        }
    }

    expect($failures)->toBe([], "Expected 403 (member is assigned to the server) but got:\n".implode("\n", $failures));
});
