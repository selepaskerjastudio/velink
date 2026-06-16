<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ProvisioningCatalog;
use App\Services\AuditLogger;
use App\Services\ProvisionService;
use App\Services\ServiceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Server $server): Response
    {
        return Inertia::render('servers/services', [
            'server' => ['id' => $server->uuid, 'name' => $server->name, 'status' => $server->status, 'public_ip' => $server->public_ip],
            'services' => $server->services()
                ->where('type', 'systemd')
                ->orderBy('name')
                ->get(['id', 'name', 'status', 'config', 'cpu_percent', 'memory_usage']),
            'jobs' => $server->agentJobs()
                ->where('type', 'shell')
                ->latest('id')
                ->limit(20)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
        ]);
    }

    public function store(Request $request, Server $server, ServiceManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:'.ServiceManager::UNIT_NAME_REGEX,
                Rule::unique('services')->where('server_id', $server->id)->where('type', 'systemd'),
            ],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $manager->register($server, $validated['name'], $validated['label'] ?? null);

        AuditLogger::log(
            action: 'service.created',
            description: "Service '{$validated['name']}' registered on '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['server_uuid' => $server->uuid],
        );

        return redirect()->route('services.index', $server);
    }

    public function provision(Request $request, Server $server, ProvisionService $provisionService, ServiceManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*' => ['required', 'string', Rule::in(ProvisioningCatalog::COMPONENTS)],
            'php_versions' => ['nullable', 'array'],
            'php_versions.*' => ['required', 'string', Rule::in(ProvisioningCatalog::PHP_VERSIONS)],
        ]);

        $components = $validated['components'];
        $phpVersions = $validated['php_versions'] ?? ['8.3'];
        $opts = in_array('php', $components, true) ? ['php_versions' => $phpVersions] : [];

        $provisionService->provision($server, $components, $opts, $request->user()->id);
        $manager->seedForServer($server, $components, $phpVersions);

        AuditLogger::log(
            action: 'server.provisioned',
            description: "Provisioning started on '{$server->name}': ".implode(', ', $components),
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['components' => $components, 'php_versions' => $phpVersions],
        );

        return redirect()->route('services.index', $server);
    }

    public function control(Request $request, Service $service, ServiceManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['start', 'stop', 'restart', 'reload', 'enable', 'disable'])],
        ]);

        $manager->control($service, $validated['action']);

        AuditLogger::log(
            action: 'service.controlled',
            description: "Service '{$service->name}' {$validated['action']}",
            userId: $request->user()->id,
            serverId: $service->server_id,
            properties: ['action' => $validated['action']],
        );

        return redirect()->route('services.index', $service->server);
    }

    public function refreshStatus(Service $service, ServiceManager $manager): RedirectResponse
    {
        $manager->refreshStatus($service);

        return redirect()->route('services.index', $service->server);
    }

    public function destroy(Service $service, ServiceManager $manager): RedirectResponse
    {
        $server = $service->server;
        $serviceName = $service->name;
        $serverId = $service->server_id;

        $manager->unregister($service);

        AuditLogger::log(
            action: 'service.deleted',
            description: "Service '{$serviceName}' deleted",
            userId: auth()->id(),
            serverId: $serverId,
        );

        return redirect()->route('services.index', $server);
    }
}
