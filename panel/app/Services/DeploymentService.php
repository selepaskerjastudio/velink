<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Deployment;
use App\Provisioning\DeployTemplates;

/**
 * Runs the "in-place" deploy: the agent fetches the configured branch and
 * runs the application's deploy script as the app's own Linux user, then
 * reports back through the same AgentJob lifecycle as provisioning jobs.
 */
class DeploymentService
{
    public function __construct(private JobDispatcher $dispatcher)
    {
    }

    public function deploy(Application $app, string $triggeredBy = 'manual', ?int $userId = null): Deployment
    {
        // Concurrency guard: reject if a deploy is already running/pending for
        // this app. Two overlapping deploys against the same root_path can
        // corrupt the working tree (concurrent git fetch/reset).
        $alreadyRunning = Deployment::where('application_id', $app->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($alreadyRunning) {
            return Deployment::create([
                'application_id' => $app->id,
                'user_id' => $userId,
                'branch' => $app->branch,
                'mode' => $app->deploy_mode,
                'status' => 'failed',
                'triggered_by' => $triggeredBy,
                'log' => 'Skipped: another deployment is already running.',
            ]);
        }

        $deployment = Deployment::create([
            'application_id' => $app->id,
            'user_id' => $userId,
            'branch' => $app->branch,
            'mode' => $app->deploy_mode,
            'status' => 'pending',
            'triggered_by' => $triggeredBy,
        ]);

        $script = trim((string) ($app->deploy_script ?: DeployTemplates::DEFAULT_SCRIPT));

        // The deploy script must run under the PHP version chosen for this app,
        // not the server's default `php`. Expose it as $PHP_BIN (e.g. php8.2);
        // the default script and any custom script use it for composer/artisan.
        $phpBin = $app->usesPhp() ? 'php'.$app->php_version : 'php';

        $inner = "set -e\n"
            .'cd '.escapeshellarg($app->root_path)."\n"
            .'export REPO_URL='.escapeshellarg($this->repoUrl($app))."\n"
            .'export BRANCH='.escapeshellarg((string) $app->branch)."\n"
            .'export PHP_BIN='.escapeshellarg($phpBin)."\n"
            .$script."\n";

        $command = sprintf('sudo -u %s -H bash -c %s', escapeshellarg($app->linux_user), escapeshellarg($inner));

        $job = $this->dispatcher->dispatch($app->server, 'shell', [
            'command' => $command,
            'timeout' => 900,
        ], ['application_id' => $app->id, 'user_id' => $userId, 'label' => "Deploy {$app->name}"]);

        $deployment->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'agent_job_uuid' => $job->uuid,
        ])->save();

        return $deployment;
    }

    /**
     * Build the remote URL the agent will clone/fetch from, embedding the
     * stored personal access token when a git credential is configured.
     */
    private function repoUrl(Application $app): string
    {
        $repo = trim((string) $app->repository, '/');
        $credential = $app->gitCredential;

        if ($credential === null) {
            return "https://github.com/{$repo}.git";
        }

        $host = $credential->provider->base_url
            ?: ($credential->provider->type === 'gitlab' ? 'gitlab.com' : 'github.com');
        $host = preg_replace('#^https?://#', '', $host);
        $host = rtrim((string) $host, '/');

        $token = (string) $credential->access_token;

        return match ($credential->provider->type) {
            'gitlab' => "https://oauth2:{$token}@{$host}/{$repo}.git",
            default => "https://x-access-token:{$token}@{$host}/{$repo}.git",
        };
    }
}
