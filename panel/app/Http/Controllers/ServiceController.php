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
                ->whereIn('type', ['systemd', 'tool'])
                ->orderByRaw("CASE type WHEN 'systemd' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'status', 'config', 'cpu_percent', 'memory_usage']),
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

    /**
     * Install (or reinstall) the single component behind a service/tool row —
     * used when a row is `not_installed` (or to repair a broken install). The
     * component to install is read from the row's config, seeded earlier.
     */
    public function install(Request $request, Service $service, ProvisionService $provisionService): RedirectResponse
    {
        $component = $service->config['component'] ?? null;
        abort_unless(is_string($component) && in_array($component, ProvisioningCatalog::COMPONENTS, true), 422);

        $phpVersion = $service->config['php_version'] ?? null;
        $opts = $component === 'php' ? ['php_versions' => array_values(array_filter([$phpVersion]))] : [];

        // Skip base — the server is already provisioned; we only add this one.
        $provisionService->provision($service->server, [$component], $opts, $request->user()->id, includeBase: false);
        $service->update(['status' => ServiceManager::STATUS_INSTALLING]);

        AuditLogger::log(
            action: 'service.install',
            description: "Install '{$service->name}' on '{$service->server->name}'",
            userId: $request->user()->id,
            serverId: $service->server_id,
            properties: ['component' => $component, 'php_version' => $phpVersion],
        );

        return redirect()->route('services.index', $service->server);
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
