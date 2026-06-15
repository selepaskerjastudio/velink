<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Service;
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
            'server' => ['id' => $server->uuid, 'name' => $server->name],
            'services' => $server->services()
                ->where('type', 'systemd')
                ->orderBy('name')
                ->get(['id', 'name', 'status', 'config']),
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

        return redirect()->route('services.index', $server);
    }

    public function control(Request $request, Service $service, ServiceManager $manager): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['start', 'stop', 'restart', 'reload', 'enable', 'disable'])],
        ]);

        $manager->control($service, $validated['action']);

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

        $manager->unregister($service);

        return redirect()->route('services.index', $server);
    }
}
