<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\GitCredential;
use App\Models\Server;
use App\Provisioning\DeployTemplates;
use App\Provisioning\ProvisioningCatalog;
use App\Services\AppProvisionService;
use App\Services\DeploymentService;
use App\Services\JobDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    private const DOMAIN_REGEX = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i';

    private const REPOSITORY_REGEX = '/^[\w.-]+\/[\w.-]+$/';

    private const BRANCH_REGEX = '/^[\w.\/-]+$/';

    public function create(Server $server): Response
    {
        return Inertia::render('applications/create', [
            'server' => ['id' => $server->uuid, 'name' => $server->name],
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
        $application->load(['server', 'gitCredential']);

        return Inertia::render('applications/show', [
            'application' => [
                ...$application->only([
                    'name', 'domain', 'root_path', 'linux_user', 'php_version', 'status', 'created_at',
                    'repository', 'branch', 'deploy_mode', 'deploy_script',
                ]),
                'id' => $application->uuid,
                'git_credential_id' => $application->gitCredential?->uuid,
                'env_content' => $application->env_content,
            ],
            'server' => ['id' => $application->server->uuid, 'name' => $application->server->name],
            'phpVersions' => ProvisioningCatalog::PHP_VERSIONS,
            'defaultDeployScript' => DeployTemplates::DEFAULT_SCRIPT,
            'gitCredentials' => auth()->user()->gitCredentials()
                ->with('provider:id,type,name')
                ->get(['id', 'uuid', 'account_username', 'git_provider_id', 'created_at'])
                ->map(fn ($c) => [
                    'id' => $c->uuid,
                    'account_username' => $c->account_username,
                    'created_at' => $c->created_at,
                    'provider' => ['type' => $c->provider->type, 'name' => $c->provider->name],
                ]),
            'deployments' => $application->deployments()
                ->latest('id')
                ->limit(20)
                ->get(['uuid', 'branch', 'mode', 'status', 'triggered_by', 'agent_job_uuid', 'log', 'started_at', 'finished_at'])
                ->map(fn ($d) => [
                    'id' => $d->uuid,
                    ...$d->only(['branch', 'mode', 'status', 'triggered_by', 'agent_job_uuid', 'log', 'started_at', 'finished_at']),
                ]),
            'jobs' => $application->server->agentJobs()
                ->where('application_id', $application->id)
                ->latest('id')
                ->limit(50)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
        ]);
    }

    public function updateDeploySettings(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate([
            'repository' => ['nullable', 'string', 'max:255', 'regex:'.self::REPOSITORY_REGEX],
            'branch' => ['required', 'string', 'max:255', 'regex:'.self::BRANCH_REGEX],
            'deploy_mode' => ['required', 'string', 'in:inplace'],
            'git_credential_id' => [
                'nullable',
                'uuid',
                Rule::exists('git_credentials', 'uuid')->where('user_id', $request->user()->id),
            ],
            'deploy_script' => ['nullable', 'string'],
        ]);

        $credential = $validated['git_credential_id']
            ? GitCredential::where('uuid', $validated['git_credential_id'])->first()
            : null;

        $application->forceFill([
            'repository' => $validated['repository'] ?: null,
            'branch' => $validated['branch'],
            'deploy_mode' => $validated['deploy_mode'],
            'git_credential_id' => $credential?->id,
            'deploy_script' => $validated['deploy_script'] ?: null,
        ])->save();

        return redirect()->route('applications.show', $application);
    }

    public function storeDeployment(Request $request, Application $application, DeploymentService $deploymentService): RedirectResponse
    {
        if (! $application->repository) {
            return redirect()->route('applications.show', $application)
                ->withErrors(['repository' => 'Set a repository before deploying.']);
        }

        $deploymentService->deploy($application, 'manual', $request->user()->id);

        return redirect()->route('applications.show', $application);
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
