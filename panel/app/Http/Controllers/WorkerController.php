<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Service;
use App\Services\AuditLogger;
use App\Services\WorkerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkerController extends Controller
{
    private const NAME_REGEX = '/^[a-z][a-z0-9_]{0,30}$/';

    public function index(Application $application): Response
    {
        $application->load('server');

        return Inertia::render('applications/workers', [
            'application' => [
                'id' => $application->uuid,
                'name' => $application->name,
            ],
            'server' => [
                'id' => $application->server->uuid,
                'name' => $application->server->name,
            ],
            'workers' => $application->services()
                ->where('type', 'supervisor')
                ->orderBy('name')
                ->get(['id', 'name', 'command', 'status', 'config']),
            'jobs' => $application->server->agentJobs()
                ->where('application_id', $application->id)
                ->whereIn('type', ['shell', 'render_config'])
                ->latest('id')
                ->limit(20)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
        ]);
    }

    public function store(Request $request, Application $application, WorkerService $service): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'regex:'.self::NAME_REGEX,
                Rule::unique('services', 'name')->where('application_id', $application->id)->where('type', 'supervisor'),
            ],
            'command' => ['required', 'string', 'max:1000'],
            'numprocs' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $service->create($application, $validated['name'], $validated['command'], $validated['numprocs'], $request->user()->id);

        AuditLogger::log(
            action: 'worker.created',
            description: "Worker '{$validated['name']}' created for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
            properties: ['app_uuid' => $application->uuid],
        );

        return redirect()->route('workers.index', $application);
    }

    public function update(Request $request, Service $worker, WorkerService $service): RedirectResponse
    {
        abort_unless($worker->type === 'supervisor', 404);

        $validated = $request->validate([
            'command' => ['required', 'string', 'max:1000'],
            'numprocs' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $service->update($worker, $validated['command'], $validated['numprocs'], $request->user()->id);

        AuditLogger::log(
            action: 'worker.updated',
            description: "Worker '{$worker->name}' updated",
            userId: $request->user()->id,
            serverId: $worker->server_id,
        );

        return redirect()->back();
    }

    public function control(Request $request, Service $worker, WorkerService $service): RedirectResponse
    {
        abort_unless($worker->type === 'supervisor', 404);

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:start,stop,restart'],
        ]);

        $service->control($worker, $validated['action'], $request->user()->id);

        AuditLogger::log(
            action: 'worker.controlled',
            description: "Worker '{$worker->name}' {$validated['action']}",
            userId: $request->user()->id,
            serverId: $worker->server_id,
            properties: ['action' => $validated['action']],
        );

        return redirect()->back();
    }

    public function destroy(Service $worker, WorkerService $service): RedirectResponse
    {
        abort_unless($worker->type === 'supervisor', 404);

        $application = $worker->application;
        $workerName = $worker->name;
        $serverId = $worker->server_id;

        $service->delete($worker, auth()->id());

        AuditLogger::log(
            action: 'worker.deleted',
            description: "Worker '{$workerName}' deleted",
            userId: auth()->id(),
            serverId: $serverId,
        );

        return redirect()->route('workers.index', $application);
    }
}
