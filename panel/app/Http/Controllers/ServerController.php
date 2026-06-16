<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Server;
use App\Provisioning\ProvisioningCatalog;
use App\Services\AuditLogger;
use App\Services\JobDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('servers/index', [
            'servers' => Server::query()
                ->orderBy('name')
                ->get(['uuid', 'name', 'hostname', 'public_ip', 'private_ip', 'os', 'status', 'agent_version', 'last_seen_at'])
                ->map(fn (Server $s) => [
                    'id' => $s->uuid,
                    'name' => $s->name,
                    'hostname' => $s->hostname,
                    'public_ip' => $s->public_ip,
                    'private_ip' => $s->private_ip,
                    'os' => $s->os,
                    'status' => $s->status,
                    'agent_version' => $s->agent_version,
                    'last_seen_at' => $s->last_seen_at,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('servers/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'public_ip' => ['nullable', 'ip'],
            'private_ip' => ['nullable', 'ip'],
            'os' => ['nullable', 'string', 'max:255'],
            'db_components' => ['nullable', 'array'],
            'db_components.*' => ['in:mariadb,postgresql,mongodb'],
        ]);

        $token = Str::random(48);

        $server = Server::create([
            ...$validated,
            'status' => 'pending',
            'agent_token' => $token,
        ]);

        AuditLogger::log(
            action: 'server.created',
            description: "Server '{$server->name}' added",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['server_uuid' => $server->uuid],
        );

        return redirect()->route('servers.connect', $server)->with([
            'plain_agent_token' => $token,
            'install_command' => $this->installCommand($server, $token),
        ]);
    }

    public function connect(Server $server): Response|RedirectResponse
    {
        // Once the agent is connected there is nothing left to install — send
        // the user straight to the server dashboard instead of the setup page.
        if ($server->status === 'online') {
            return redirect()->route('servers.show', $server);
        }

        return Inertia::render('servers/connect', [
            'server' => [
                'id' => $server->uuid,
                'name' => $server->name,
                'public_ip' => $server->public_ip,
                'status' => $server->status,
            ],
        ]);
    }

    public function show(Server $server): Response
    {
        return Inertia::render('servers/show', [
            'server' => [
                ...$server->only(['name', 'hostname', 'public_ip', 'private_ip', 'os', 'status', 'agent_version', 'last_seen_at']),
                'id' => $server->uuid,
            ],
            'applications' => $server->applications()
                ->orderBy('name')
                ->get(['uuid', 'name', 'domain', 'php_version', 'status'])
                ->map(fn (Application $a) => [
                    'id' => $a->uuid,
                    'name' => $a->name,
                    'domain' => $a->domain,
                    'php_version' => $a->php_version,
                    'status' => $a->status,
                ]),
            'jobs' => $server->agentJobs()
                ->whereNull('application_id')
                ->latest('id')
                ->limit(50)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
            'phpVersions' => ProvisioningCatalog::PHP_VERSIONS,
            'dbComponents' => ProvisioningCatalog::DB_COMPONENTS,
            'recentMetrics' => $server->metrics()
                ->orderBy('recorded_at')
                ->limit(120)
                ->get(['cpu_percent', 'mem_total', 'mem_used', 'disk_total', 'disk_used', 'load1', 'recorded_at'])
                ->map(fn ($m) => [
                    'cpu'   => round($m->cpu_percent, 1),
                    'ram'   => $m->mem_total > 0 ? round($m->mem_used / $m->mem_total * 100, 1) : 0,
                    'disk'  => $m->disk_total > 0 ? round($m->disk_used / $m->disk_total * 100, 1) : 0,
                    'load1' => round($m->load1, 2),
                    'ts'    => $m->recorded_at->format('H:i:s'),
                ]),
            'latestMetric' => $server->metrics()
                ->latest('recorded_at')
                ->first(['cpu_percent', 'mem_total', 'mem_used', 'disk_total', 'disk_used', 'load1', 'uptime_seconds', 'recorded_at']),
            'counts' => [
                'applications' => $server->applications()->count(),
                'databases'    => $server->databases()->count(),
                'cron_jobs'    => $server->cronJobs()->count(),
                'workers'      => $server->services()->where('type', 'supervisor')->count(),
            ],
        ]);
    }

    public function monitoring(Request $request, Server $server): Response
    {
        $hours = match($request->query('range', '1h')) {
            '6h'  => 6,
            '24h' => 24,
            '7d'  => 168,
            default => 1,
        };

        return Inertia::render('servers/monitoring', [
            'server' => [
                ...$server->only(['name', 'hostname', 'public_ip', 'os', 'status', 'agent_version', 'last_seen_at']),
                'id' => $server->uuid,
            ],
            'range' => $request->query('range', '1h'),
            'metrics' => $server->metrics()
                ->where('recorded_at', '>=', now()->subHours($hours))
                ->orderBy('recorded_at')
                ->get(['cpu_percent', 'mem_total', 'mem_used', 'disk_total', 'disk_used', 'load1', 'uptime_seconds', 'recorded_at'])
                ->map(fn ($m) => [
                    'cpu'     => round($m->cpu_percent, 1),
                    'ram'     => $m->mem_total > 0 ? round($m->mem_used / $m->mem_total * 100, 1) : 0,
                    'ram_gb'  => round($m->mem_used / 1024 / 1024 / 1024, 2),
                    'disk'    => $m->disk_total > 0 ? round($m->disk_used / $m->disk_total * 100, 1) : 0,
                    'disk_gb' => round($m->disk_used / 1024 / 1024 / 1024, 2),
                    'load1'   => round($m->load1, 2),
                    'ts'      => $m->recorded_at->format('H:i'),
                ]),
            'latestMetric' => $server->metrics()
                ->latest('recorded_at')
                ->first(['cpu_percent', 'mem_total', 'mem_used', 'disk_total', 'disk_used', 'load1', 'uptime_seconds', 'recorded_at']),
        ]);
    }

    public function settings(Server $server): Response
    {
        return Inertia::render('servers/settings', [
            'server' => [
                ...$server->only(['name', 'hostname', 'public_ip', 'private_ip', 'os', 'status']),
                'id' => $server->uuid,
            ],
        ]);
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $server->update($validated);

        return redirect()->route('servers.settings', $server)->with('success', 'Server name updated.');
    }

    public function restart(Request $request, Server $server, JobDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->dispatch($server, 'shell', ['command' => 'sudo reboot'], [
            'user_id' => $request->user()->id,
            'label'   => 'Restart server',
        ]);

        return redirect()->route('servers.settings', $server)->with('success', 'Restart command dispatched.');
    }

    public function regenerateToken(Request $request, Server $server): RedirectResponse
    {
        $token = Str::random(48);
        $server->update(['agent_token' => Hash::make($token)]);

        AuditLogger::log(
            action: 'server.token_regenerated',
            description: "Agent token regenerated for '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
        );

        return redirect()->route('servers.connect', $server)->with([
            'plain_agent_token' => $token,
            'install_command' => $this->installCommand($server, $token),
        ]);
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        AuditLogger::log(
            action: 'server.deleted',
            description: "Server '{$server->name}' deleted",
            userId: $request->user()->id,
            properties: ['server_uuid' => $server->uuid, 'hostname' => $server->hostname],
        );

        $server->delete();

        return redirect()->route('servers.index');
    }

    private function installCommand(Server $server, string $token): string
    {
        $panelUrl = rtrim(config('app.url'), '/');

        return sprintf(
            'curl -fsSL %s/install/agent.sh | sudo bash -s -- --token=%s --server-id=%s',
            $panelUrl,
            $token,
            $server->uuid,
        );
    }
}
