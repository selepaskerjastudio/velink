<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\GitCredential;
use App\Models\Server;
use App\Provisioning\DeployTemplates;
use App\Provisioning\ProvisioningCatalog;
use App\Services\AppProvisionService;
use App\Services\AuditLogger;
use App\Services\DatabaseProvisionService;
use App\Services\DatabaseUserProvisionService;
use App\Services\DeploymentService;
use App\Services\DeployScriptValidator;
use App\Services\JobDispatcher;
use App\Support\DatabaseNaming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                'id' => $server->uuid,
                'name' => $server->name,
                'public_ip' => $server->public_ip,
                'status' => $server->status,
            ],
            'applications' => $server->applications()
                ->orderBy('name')
                ->get(['uuid', 'name', 'domain', 'php_version', 'linux_user', 'status'])
                ->map(fn (Application $a) => [
                    'id' => $a->uuid,
                    'name' => $a->name,
                    'domain' => $a->domain,
                    'php_version' => $a->php_version,
                    'linux_user' => $a->linux_user,
                    'status' => $a->status,
                ]),
        ]);
    }

    public function create(Request $request, Server $server): Response
    {
        return Inertia::render('applications/create', [
            'server' => ['id' => $server->uuid, 'name' => $server->name, 'os' => $server->os],
            'phpVersions' => ProvisioningCatalog::PHP_VERSIONS,
            'appTypes' => self::appTypes(),
            'installedEngines' => $server->installedDatabaseEngines(),
            'gitCredentials' => $this->gitCredentialsFor($request),
        ]);
    }

    public function store(Request $request, Server $server, AppProvisionService $provisionService, DatabaseProvisionService $databaseService, DatabaseUserProvisionService $databaseUserService): RedirectResponse
    {
        $appType = (string) $request->input('app_type');
        $installedEngines = $server->installedDatabaseEngines();
        // WordPress always needs a (MySQL/MariaDB) database.
        $wantsDb = $request->boolean('create_database') || $appType === 'wordpress';

        $validated = $request->validate([
            'app_type' => ['required', 'string', Rule::in(Application::APP_TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'regex:'.self::DOMAIN_REGEX, Rule::unique('applications', 'domain')],
            'stack_mode' => ['required', 'string', Rule::in(['production', 'development'])],
            'php_version' => [Rule::requiredIf($appType !== 'static'), 'nullable', 'string', 'in:'.implode(',', ProvisioningCatalog::PHP_VERSIONS)],

            // Git (optional)
            'repository' => ['nullable', 'string', 'max:255', 'regex:'.self::REPOSITORY_REGEX],
            'branch' => ['required', 'string', 'max:255', 'regex:'.self::BRANCH_REGEX],
            'git_credential_id' => [
                'nullable',
                'uuid',
                Rule::exists('git_credentials', 'uuid')->where('user_id', $request->user()->id),
            ],

            // Database (optional; required for WordPress)
            'create_database' => ['boolean'],
            'db_engine' => [
                Rule::requiredIf($wantsDb),
                'nullable',
                'string',
                Rule::in($installedEngines),
                // WordPress only supports MySQL/MariaDB.
                Rule::when($appType === 'wordpress', [Rule::in(['mariadb'])]),
            ],
            'db_name' => [
                Rule::requiredIf($wantsDb),
                'nullable',
                'string',
                'regex:'.DatabaseNaming::DB_NAME_REGEX,
                function ($attribute, $value, $fail) {
                    if ($value !== null && DatabaseNaming::isReserved((string) $value)) {
                        $fail('The database name is reserved.');
                    }
                },
                Rule::unique('databases', 'name')
                    ->where('server_id', $server->id)
                    ->where('engine', $request->input('db_engine')),
            ],
            'db_username' => [
                Rule::requiredIf($wantsDb),
                'nullable',
                'string',
                'regex:'.DatabaseNaming::USERNAME_REGEX,
            ],
        ]);

        $appSlug = Application::generateAppSlug($server->id, $validated['domain']);
        $osUser = (string) config('velink.webapp_user', 'velink');

        $credential = ! empty($validated['git_credential_id'])
            ? GitCredential::where('uuid', $validated['git_credential_id'])->first()
            : null;

        $application = Application::create([
            'server_id' => $server->id,
            'name' => $validated['name'],
            'domain' => $validated['domain'],
            'app_type' => $validated['app_type'],
            'stack_mode' => $validated['stack_mode'],
            'linux_user' => $osUser,
            'app_slug' => $appSlug,
            'root_path' => "/home/{$osUser}/webapps/{$appSlug}",
            'php_version' => $validated['php_version'] ?? ProvisioningCatalog::PHP_VERSIONS[count(ProvisioningCatalog::PHP_VERSIONS) - 1],
            'repository' => ($validated['repository'] ?? '') ?: null,
            'branch' => $validated['branch'],
            'git_credential_id' => $credential?->id,
            'status' => 'provisioning',
        ]);

        // Optionally provision a database + dedicated user in the same flow.
        $dbCreds = null;
        if ($wantsDb) {
            $databaseService->create(
                $server,
                $validated['db_engine'],
                $validated['db_name'],
                null,
                null,
                $request->user()->id,
            );

            $userResult = $databaseUserService->create(
                $server,
                $validated['db_engine'],
                $validated['db_username'],
                'localhost',
                [$validated['db_name'] => ['ALL']],
                $request->user()->id,
            );

            $dbCreds = [
                'name' => $validated['db_name'],
                'user' => $validated['db_username'],
                'password' => $userResult['plainPassword'],
                'host' => 'localhost',
            ];
        }

        $provisionService->provisionNew($application, $request->user()->id, $dbCreds);

        AuditLogger::log(
            action: 'application.created',
            description: "Application '{$application->name}' created on '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['app_uuid' => $application->uuid, 'app_type' => $application->app_type],
        );

        return redirect()->route('applications.show', $application);
    }

    /**
     * The selectable application types shown as cards on the create form.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private static function appTypes(): array
    {
        return [
            ['value' => 'custom', 'label' => 'Custom Web App (PHP)', 'description' => 'Generic PHP app served from a /public front controller.'],
            ['value' => 'laravel', 'label' => 'Laravel', 'description' => 'PHP app with a /public web root and Laravel-friendly defaults.'],
            ['value' => 'wordpress', 'label' => 'WordPress', 'description' => 'Downloads WordPress core and wires wp-config to a new database.'],
            ['value' => 'static', 'label' => 'Static HTML', 'description' => 'nginx serves files straight from disk — no PHP.'],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function gitCredentialsFor(Request $request)
    {
        return $request->user()->gitCredentials()
            ->with('provider:id,type,name')
            ->get(['id', 'uuid', 'account_username', 'git_provider_id', 'created_at'])
            ->map(fn ($c) => [
                'id' => $c->uuid,
                'account_username' => $c->account_username,
                'created_at' => $c->created_at,
                'provider' => ['type' => $c->provider->type, 'name' => $c->provider->name],
            ]);
    }

    public function show(Application $application): Response
    {
        $application->load(['server', 'gitCredential']);

        return Inertia::render('applications/show', [
            'application' => [
                ...$application->only([
                    'name', 'domain', 'root_path', 'linux_user', 'php_version', 'app_type', 'stack_mode', 'status', 'created_at',
                    'repository', 'branch', 'deploy_mode', 'deploy_script', 'webhook_secret',
                ]),
                'id' => $application->uuid,
                'git_credential_id' => $application->gitCredential?->uuid,
                'env_content' => $application->env_content,
                'webhook_url' => route('webhooks.github', $application),
                'webhook_url_gitlab' => route('webhooks.gitlab', $application),
                'ssl_enabled' => $application->ssl_enabled_at !== null,
                'ssl_enabled_at' => $application->ssl_enabled_at?->toIso8601String(),
                'ssl_provider' => $application->ssl_enabled_at !== null ? 'letsencrypt' : null,
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
                throw ValidationException::withMessages([
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

        $domain = escapeshellarg($application->domain);
        $email = escapeshellarg($request->user()->email);

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

    public function nginxConfig(Request $request, Application $application, JobDispatcher $dispatcher): RedirectResponse
    {
        $validated = $request->validate([
            'config' => ['required', 'string', 'max:65535'],
        ]);

        $configPath = "/etc/nginx/sites-available/{$application->domain}.conf";

        $dispatcher->dispatch($application->server, 'write_file', [
            'path' => $configPath,
            'content' => $validated['config'],
        ], [
            'application_id' => $application->id,
            'user_id' => $request->user()->id,
            'label' => 'Update NGINX config',
        ]);

        $dispatcher->dispatch($application->server, 'shell', [
            'command' => 'sudo nginx -t && sudo systemctl reload nginx',
        ], [
            'application_id' => $application->id,
            'user_id' => $request->user()->id,
            'label' => 'Reload NGINX',
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
