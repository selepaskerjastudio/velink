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

        $user = $request->user();

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                // Named fields rather than the whole model: this is shared on
                // every request, so a column added to `users` later (e.g. an
                // invite token) does not silently start shipping to the browser.
                'user' => $user ? [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                    'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                ] : null,
                // The admin/member split is purely role-based (see
                // docs/ACCESS_CONTROL.md §2), so this one boolean is enough to
                // gate every carved-out action in the UI — no per-route map.
                'can' => [
                    'admin' => (bool) $user?->isAdmin(),
                ],
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
