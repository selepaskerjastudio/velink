<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Provisioning\ProvisioningCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                ->get(['id', 'name', 'hostname', 'public_ip', 'private_ip', 'os', 'status', 'agent_version', 'last_seen_at']),
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
        ]);

        $token = Str::random(48);

        $server = Server::create([
            ...$validated,
            'status' => 'pending',
            'agent_token' => $token,
        ]);

        return redirect()->route('servers.show', $server)->with([
            'plain_agent_token' => $token,
            'install_command' => $this->installCommand($server, $token),
        ]);
    }

    public function show(Server $server): Response
    {
        return Inertia::render('servers/show', [
            'server' => $server,
            'jobs' => $server->agentJobs()
                ->latest('id')
                ->limit(50)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
            'provisioningComponents' => ProvisioningCatalog::COMPONENTS,
            'phpVersions' => ProvisioningCatalog::PHP_VERSIONS,
        ]);
    }

    private function installCommand(Server $server, string $token): string
    {
        $panelUrl = rtrim(config('app.url'), '/');
        $gatewayUrl = rtrim((string) config('services.gateway.public_url'), '/');

        return sprintf(
            'curl -fsSL %s/install/agent.sh | sudo bash -s -- --token=%s --panel=%s --gateway=%s --server-id=%d',
            $panelUrl,
            $token,
            $panelUrl,
            $gatewayUrl,
            $server->id,
        );
    }
}
