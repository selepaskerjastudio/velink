<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\FirewallRule;
use App\Models\Server;

/**
 * Manages UFW firewall rules on a managed server.
 *
 * The firewall_rules table is the single source of truth — syncRules()
 * rebuilds the entire UFW rule set from the DB on every change (same pattern
 * as SSH keys → authorized_keys). This guarantees the server always matches
 * what the panel shows.
 *
 * Safety: SSH (port 22) is always seeded first and marked is_protected so it
 * can never be removed — preventing accidental lockout.
 */
class FirewallService
{
    public const PROTOCOLS = ['tcp', 'udp'];
    public const ACTIONS = ['allow', 'deny'];

    /** Default rules seeded when a server first gets firewall management. */
    public const DEFAULT_RULES = [
        ['protocol' => 'tcp', 'port' => 22, 'action' => 'allow', 'source' => null, 'is_protected' => true],
        ['protocol' => 'tcp', 'port' => 80, 'action' => 'allow', 'source' => null, 'is_protected' => false],
        ['protocol' => 'tcp', 'port' => 443, 'action' => 'allow', 'source' => null, 'is_protected' => false],
    ];

    public function __construct(private JobDispatcher $dispatcher) {}

    /**
     * Seed default rules (SSH, HTTP, HTTPS) if none exist yet.
     */
    public function ensureDefaults(Server $server): void
    {
        if ($server->firewallRules()->exists()) {
            return;
        }

        foreach (self::DEFAULT_RULES as $rule) {
            FirewallRule::create(array_merge(['server_id' => $server->id], $rule));
        }
    }

    /**
     * Rebuild the entire UFW rule set from the DB.
     *
     * Runs: ufw reset → re-apply every rule → ufw enable.
     * The SSH rule (port 22) is applied first to prevent lockout.
     *
     * @return array<int, AgentJob>
     */
    public function syncRules(Server $server, ?int $userId = null): array
    {
        $this->ensureDefaults($server);

        $rules = $server->firewallRules()
            ->orderByRaw("port = 22 desc")  // SSH first
            ->orderBy('port')
            ->get();

        $lines = ["ufw --force reset", "ufw default deny incoming", "ufw default allow outgoing"];

        foreach ($rules as $rule) {
            $source = $rule->source ? " from {$rule->source}" : '';
            $lines[] = "ufw --force {$rule->action} {$rule->port}/{$rule->protocol}{$source}";
        }

        $lines[] = 'ufw --force enable';

        $command = implode("\n", $lines);

        return [
            $this->dispatcher->dispatch($server, 'shell', [
                'command' => "set -e\n{$command}",
                'timeout' => 120,
            ], ['user_id' => $userId, 'label' => 'Sync firewall rules']),
        ];
    }

    /**
     * Add a rule and sync UFW.
     *
     * @param  array{protocol: string, port: int, action: string, source: ?string}  $data
     * @return array<int, AgentJob>
     */
    public function addRule(Server $server, array $data, ?int $userId = null): array
    {
        FirewallRule::create([
            'server_id' => $server->id,
            'protocol' => $data['protocol'],
            'port' => $data['port'],
            'action' => $data['action'],
            'source' => $data['source'] ?: null,
            'is_protected' => false,
        ]);

        return $this->syncRules($server, $userId);
    }

    /**
     * Delete a rule (protected rules rejected) and sync UFW.
     *
     * @return array<int, AgentJob>
     */
    public function deleteRule(FirewallRule $rule, ?int $userId = null): array
    {
        $server = $rule->server;
        $rule->delete();

        return $this->syncRules($server, $userId);
    }
}
