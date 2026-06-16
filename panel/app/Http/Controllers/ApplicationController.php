<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\GitCredential;
use App\Models\Server;
use App\Provisioning\DeployTemplates;
use App\Provisioning\ProvisioningCatalog;
use App\Services\AppProvisionService;
use App\Services\AuditLogger;
use App\Services\DeployScriptValidator;
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

    public function serverIndex(Server $server): Response
    {
        return Inertia::render('servers/applications', [
            'server' => [
                'id'        => $server->uuid,
                'name'      => $server->name,
                'public_ip' => $server->public_ip,
                'status'    => $server->status,
            ],
            'applications' => $server->applications()
                ->orderBy('name')
                ->get(['uuid', 'name', 'domain', 'php_version', 'linux_user', 'status'])
                ->map(fn (Application $a) => [
                    'id'          => $a->uuid,
                    'name'        => $a->name,
                    'domain'      => $a->domain,
                    'php_version' => $a->php_version,
                    'linux_user'  => $a->linux_user,
                    'status'      => $a->status,
                ]),
        ]);
    }

    public function create(Server $server): Response
    {
        return Inertia::render('applications/create', [
            'server' => ['id' => $server->uuid, 'name' => $server->name, 'os' => $server->os],
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

        AuditLogger::log(
            action: 'application.created',
            description: "Application '{$application->name}' created on '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['app_uuid' => $application->uuid],
        );

        return redirect()->route('applications.show', $application);
    }

    public function show(Application $application): Response
    {
        $application->load(['server', 'gitCredential']);

        return Inertia::render('applications/show', [
            'application' => [
                ...$application->only([
                    'name', 'domain', 'root_path', 'linux_user', 'php_version', 'status', 'created_at',
                    'repository', 'branch', 'deploy_mode', 'deploy_script', 'webhook_secret',
                ]),
                'id' => $application->uuid,
                'git_credential_id' => $application->gitCredential?->uuid,
                'env_content' => $application->env_content,
                'webhook_url' => route('webhooks.github', $application),
                'webhook_url_gitlab' => route('webhooks.gitlab', $application),
                'ssl_status' => $application->ssl_status ?? 'none',
            ],
            'server' => ['id' => $application->server->uuid, 'name' => $application->server->name, 'status' => $application->server->status, 'os' => $application->server->os],
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

        if (! empty($validated['deploy_script'])) {
            $warnings = DeployScriptValidator::check($validated['deploy_script']);
            if (! empty($warnings)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'deploy_script' => $warnings,
                ]);
            }
        }

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

        AuditLogger::log(
            action: 'application.deploy_settings_updated',
            description: "Deploy settings updated for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
            properties: [
                'repository' => $validated['repository'] ?: null,
                'branch' => $validated['branch'],
            ],
        );

        return redirect()->route('applications.show', $application);
    }

    public function storeDeployment(Request $request, Application $application, DeploymentService $deploymentService): RedirectResponse
    {
        if (! $application->repository) {
            return redirect()->route('applications.show', $application)
                ->withErrors(['repository' => 'Set a repository before deploying.']);
        }

        $deploymentService->deploy($application, 'manual', $request->user()->id);

        AuditLogger::log(
            action: 'application.deployed',
            description: "Deploy triggered for '{$application->name}' (manual)",
            userId: $request->user()->id,
            serverId: $application->server_id,
            properties: [
                'branch' => $application->branch,
                'mode' => 'manual',
            ],
        );

        return redirect()->route('applications.show', $application);
    }

    public function updatePhpVersion(Request $request, Application $application, AppProvisionService $provisionService): RedirectResponse
    {
        $validated = $request->validate([
            'php_version' => ['required', 'string', 'in:'.implode(',', ProvisioningCatalog::PHP_VERSIONS)],
        ]);

        if ($validated['php_version'] !== $application->php_version) {
            $provisionService->changePhpVersion($application, $validated['php_version'], $request->user()->id);

            AuditLogger::log(
                action: 'application.php_version_changed',
                description: "PHP changed to {$validated['php_version']} for '{$application->name}'",
                userId: $request->user()->id,
                serverId: $application->server_id,
                properties: ['php_version' => $validated['php_version']],
            );
        }

        return redirect()->route('applications.show', $application);
    }

    public function enableSsl(Request $request, Application $application, JobDispatcher $dispatcher): RedirectResponse
    {
        if (! $application->domain) {
            return redirect()->back()->withErrors(['domain' => 'Application has no domain configured.']);
        }

        if ($application->status === 'pending') {
            return redirect()->back()->withErrors(['domain' => 'Application is not yet provisioned.']);
        }

        if (in_array($application->ssl_status, ['active', 'requesting'], true)) {
            return redirect()->back()->withErrors(['domain' => 'SSL is already active or being requested.']);
        }

        $domain = escapeshellarg($application->domain);
        $email = escapeshellarg($request->user()->email);

        $application->forceFill(['ssl_status' => 'requesting'])->save();

        $dispatcher->dispatch($application->server, 'shell', [
            'command' => "certbot --nginx -d {$domain} --non-interactive --agree-tos --email {$email} --redirect",
            'timeout' => 120,
        ], [
            'application_id' => $application->id,
            'user_id' => $request->user()->id,
            'label' => "Enable SSL for {$application->domain}",
        ]);

        AuditLogger::log(
            action: 'application.ssl_enabled',
            description: "SSL requested for '{$application->name}' ({$application->domain})",
            userId: $request->user()->id,
            serverId: $application->server_id,
        );

        return redirect()->route('applications.show', $application);
    }

    public function checkSsl(Request $request, Application $application, JobDispatcher $dispatcher): RedirectResponse
    {
        if (! $application->domain) {
            return redirect()->back()->withErrors(['domain' => 'Application has no domain configured.']);
        }

        $domain = escapeshellarg($application->domain);

        $dispatcher->dispatch($application->server, 'shell', [
            'command' => "certbot certificates --cert-name {$domain} 2>&1 || echo 'NOT_FOUND'",
            'timeout' => 30,
        ], [
            'application_id' => $application->id,
            'user_id' => $request->user()->id,
            'label' => "Check SSL for {$application->domain}",
        ]);

        AuditLogger::log(
            action: 'application.ssl_checked',
            description: "SSL status check for '{$application->name}' ({$application->domain})",
            userId: $request->user()->id,
            serverId: $application->server_id,
        );

        return redirect()->route('applications.show', $application);
    }

    public function nginxConfig(Request $request, Application $application, JobDispatcher $dispatcher): RedirectResponse
    {
        $validated = $request->validate([
            'config' => ['required', 'string', 'max:65535'],
        ]);

        $configPath = "/etc/nginx/sites-available/{$application->domain}.conf";

        $dispatcher->dispatch($application->server, 'write_file', [
            'path'    => $configPath,
            'content' => $validated['config'],
        ], [
            'application_id' => $application->id,
            'user_id'        => $request->user()->id,
            'label'          => 'Update NGINX config',
        ]);

        $dispatcher->dispatch($application->server, 'shell', [
            'command' => 'sudo nginx -t && sudo systemctl reload nginx',
        ], [
            'application_id' => $application->id,
            'user_id'        => $request->user()->id,
            'label'          => 'Reload NGINX',
        ]);

        AuditLogger::log(
            action: 'application.nginx_config_updated',
            description: "NGINX config updated for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
        );

        return redirect()->route('applications.show', $application)->with('success', 'NGINX config updated and reloaded.');
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

        AuditLogger::log(
            action: 'application.env_updated',
            description: "'.env' updated for '{$application->name}'",
            userId: $request->user()->id,
            serverId: $application->server_id,
        );

        return redirect()->route('applications.show', $application);
    }
}
