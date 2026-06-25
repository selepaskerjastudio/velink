<?php

namespace App\Http\Controllers;

use App\Models\FirewallRule;
use App\Models\Server;
use App\Provisioning\ProvisioningCatalog;
use App\Services\AuditLogger;
use App\Services\Fail2BanService;
use App\Services\FirewallService;
use App\Services\ProvisionService;
use App\Services\ServiceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function __construct(
        private FirewallService $firewall,
        private Fail2BanService $fail2ban,
    ) {}

    /**
     * Render the Security page (Firewall + Fail2Ban tabs).
     */
    public function index(Request $request, Server $server): Response
    {
        // Ensure default firewall rules exist.
        $this->firewall->ensureDefaults($server);

        $rules = $server->firewallRules()
            ->orderByRaw('port = 22 desc')
            ->orderBy('port')
            ->get(['id', 'uuid', 'protocol', 'port', 'action', 'source', 'is_protected'])
            ->map(fn (FirewallRule $r) => [
                'id' => $r->uuid,
                'protocol' => $r->protocol,
                'port' => $r->port,
                'action' => $r->action,
                'source' => $r->source,
                'is_protected' => $r->is_protected,
            ]);

        // Check if ufw/fail2ban are provisioned.
        $ufwStatus = $server->services()->where('name', 'ufw')->value('status');
        $fail2banStatus = $server->services()->where('name', 'fail2ban')->value('status');
        $ufwInstalled = in_array($ufwStatus, ['running', 'active'], true);
        $fail2banInstalled = in_array($fail2banStatus, ['running', 'active'], true);

        return Inertia::render('servers/security', [
            'server' => [
                ...$server->only(['name', 'public_ip', 'status']),
                'id' => $server->uuid,
            ],
            'firewallRules' => $rules,
            'ufwInstalled' => $ufwInstalled,
            'fail2banInstalled' => $fail2banInstalled,
            'jobs' => $server->agentJobs()
                ->latest('id')
                ->limit(20)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
        ]);
    }

    /**
     * Add a firewall rule and sync UFW.
     */
    public function storeRule(Request $request, Server $server): RedirectResponse
    {
        $validated = $request->validate([
            'protocol' => ['required', Rule::in(FirewallService::PROTOCOLS)],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'action' => ['required', Rule::in(FirewallService::ACTIONS)],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        // Check for duplicate.
        $exists = $server->firewallRules()
            ->where('protocol', $validated['protocol'])
            ->where('port', $validated['port'])
            ->where('action', $validated['action'])
            ->where('source', $validated['source'] ?: null)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'port' => 'An identical firewall rule already exists.',
            ]);
        }

        $this->firewall->addRule($server, $validated, $request->user()->id);

        AuditLogger::log(
            action: 'firewall.rule_added',
            description: "Firewall rule added: {$validated['action']} {$validated['port']}/{$validated['protocol']}",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: $validated,
        );

        return redirect()->route('security.index', $server);
    }

    /**
     * Delete a firewall rule (protected rules rejected) and sync UFW.
     */
    public function destroyRule(Request $request, Server $server, FirewallRule $rule): RedirectResponse
    {
        if ($rule->is_protected) {
            abort(403, 'Protected firewall rules cannot be deleted.');
        }

        $this->firewall->deleteRule($rule, $request->user()->id);

        AuditLogger::log(
            action: 'firewall.rule_deleted',
            description: "Firewall rule removed: {$rule->action} {$rule->port}/{$rule->protocol}",
            userId: $request->user()->id,
            serverId: $server->id,
        );

        return redirect()->route('security.index', $server);
    }

    /**
     * Provision fail2ban on the server.
     */
    public function installFail2Ban(Request $request, Server $server, ProvisionService $provision): RedirectResponse
    {
        $provision->provision($server, ['fail2ban'], userId: $request->user()->id, includeBase: false);

        app(ServiceManager::class)->seedForServer($server, ['fail2ban'], []);

        AuditLogger::log(
            action: 'fail2ban.installed',
            description: "Fail2Ban installation triggered on '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
        );

        return redirect()->route('security.index', $server);
    }

    /**
     * Manually ban an IP via fail2ban-client.
     */
    public function banIp(Request $request, Server $server): RedirectResponse
    {
        $validated = $request->validate([
            'ip' => ['required', 'ip'],
        ]);

        $this->fail2ban->banIp($server, $validated['ip'], $request->user()->id);

        AuditLogger::log(
            action: 'fail2ban.banned',
            description: "IP {$validated['ip']} banned via Fail2Ban",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['ip' => $validated['ip']],
        );

        return redirect()->route('security.index', $server);
    }

    /**
     * Unban an IP via fail2ban-client.
     */
    public function unbanIp(Request $request, Server $server, string $ip): RedirectResponse
    {
        $this->fail2ban->unbanIp($server, $ip, $request->user()->id);

        AuditLogger::log(
            action: 'fail2ban.unbanned',
            description: "IP {$ip} unbanned via Fail2Ban",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['ip' => $ip],
        );

        return redirect()->route('security.index', $server);
    }
}
