<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;

/**
 * Stateless Fail2Ban management — all actions are agent shell jobs.
 *
 * Fail2Ban runs server-side and auto-bans IPs that fail SSH auth too many
 * times. This service provides manual ban/unban and status queries through
 * the agent's shell executor (runs as root).
 */
class Fail2BanService
{
    public function __construct(private JobDispatcher $dispatcher) {}

    /**
     * Dispatch a job to query the current banned IPs from fail2ban-client.
     * The output streams back via the gateway and is visible in the job log.
     */
    public function refreshStatus(Server $server, ?int $userId = null): AgentJob
    {
        return $this->dispatch($server, 'Refresh Fail2Ban status', 'fail2ban-client status sshd', $userId);
    }

    /**
     * Manually ban an IP address.
     */
    public function banIp(Server $server, string $ip, ?int $userId = null): AgentJob
    {
        $ip = escapeshellarg($ip);

        return $this->dispatch($server, "Ban IP {$ip}", "fail2ban-client set sshd banip {$ip}", $userId);
    }

    /**
     * Remove a manual ban on an IP address.
     */
    public function unbanIp(Server $server, string $ip, ?int $userId = null): AgentJob
    {
        $ip = escapeshellarg($ip);

        return $this->dispatch($server, "Unban IP {$ip}", "fail2ban-client set sshd unbanip {$ip}", $userId);
    }

    private function dispatch(Server $server, string $label, string $command, ?int $userId): AgentJob
    {
        return $this->dispatcher->dispatch($server, 'shell', [
            'command' => "set -e\necho \"==> {$label}\"\n{$command}",
            'timeout' => 30,
        ], ['user_id' => $userId, 'label' => $label]);
    }
}
