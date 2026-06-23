<?php

namespace App\Http\Middleware;

use App\Models\AgentJob;
use App\Models\Server;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'plainAgentToken' => $request->session()->get('plain_agent_token'),
                'installCommand' => $request->session()->get('install_command'),
                'plainDbUserPassword' => $request->session()->get('plain_db_user_password'),
                'plainDbUserUsername' => $request->session()->get('plain_db_user_username'),
            ],
            'server_provisioning' => function () use ($request): bool {
                $server = $request->route('server');
                if (! $server instanceof Server) {
                    return false;
                }

                return $server->agentJobs()
                    ->whereNull('application_id')
                    ->whereNotIn('status', AgentJob::TERMINAL_STATUSES)
                    ->exists();
            },
        ]);
    }
}
