<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Server;
use App\Provisioning\ProvisioningCatalog;
use App\Services\AppProvisionService;
use App\Services\JobDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    private const DOMAIN_REGEX = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i';

    public function create(Server $server): Response
    {
        return Inertia::render('applications/create', [
            'server' => $server->only(['id', 'name']),
            'phpVersions' => ProvisioningCatalog::PHP_VERSIONS,
        ]);
    }

    public function store(Request $request, Server $server, AppProvisionService $provisionService): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'regex:'.self::DOMAIN_REGEX, Rule::unique('applications', 'domain')],
            'php_version' => ['required', 'string', 'in:'.implode(',', ProvisioningCatalog::PHP_VERSIONS)],
        ]);

        $linuxUser = Application::generateLinuxUser($server->id, $validated['domain']);

        $application = Application::create([
            'server_id' => $server->id,
            'name' => $validated['name'],
            'domain' => $validated['domain'],
            'root_path' => "/home/{$linuxUser}",
            'linux_user' => $linuxUser,
            'php_version' => $validated['php_version'],
            'status' => 'provisioning',
        ]);

        $provisionService->provisionNew($application, $request->user()->id);

        return redirect()->route('applications.show', $application);
    }

    public function show(Application $application): Response
    {
        $application->load('server');

        return Inertia::render('applications/show', [
            'application' => [
                ...$application->only(['id', 'server_id', 'name', 'domain', 'root_path', 'linux_user', 'php_version', 'status', 'created_at']),
                'env_content' => $application->env_content,
            ],
            'server' => $application->server->only(['id', 'name']),
            'phpVersions' => ProvisioningCatalog::PHP_VERSIONS,
            'jobs' => $application->server->agentJobs()
                ->where('application_id', $application->id)
                ->latest('id')
                ->limit(50)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
        ]);
    }

    public function updatePhpVersion(Request $request, Application $application, AppProvisionService $provisionService): RedirectResponse
    {
        $validated = $request->validate([
            'php_version' => ['required', 'string', 'in:'.implode(',', ProvisioningCatalog::PHP_VERSIONS)],
        ]);

        if ($validated['php_version'] !== $application->php_version) {
            $provisionService->changePhpVersion($application, $validated['php_version'], $request->user()->id);
        }

        return redirect()->route('applications.show', $application);
    }

    public function updateEnv(Request $request, Application $application, JobDispatcher $dispatcher): RedirectResponse
    {
        $validated = $request->validate([
            'env_content' => ['nullable', 'string'],
        ]);

        $application->forceFill(['env_content' => $validated['env_content'] ?? ''])->save();

        $dispatcher->dispatch($application->server, 'write_file', [
            'path' => "{$application->root_path}/.env",
            'content' => $validated['env_content'] ?? '',
            'mode' => '0640',
        ], [
            'application_id' => $application->id,
            'user_id' => $request->user()->id,
            'label' => 'Write .env',
        ]);

        return redirect()->route('applications.show', $application);
    }
}
