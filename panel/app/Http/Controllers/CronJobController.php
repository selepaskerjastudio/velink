<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Models\Server;
use App\Provisioning\CronTemplates;
use App\Services\AuditLogger;
use App\Services\CronService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CronJobController extends Controller
{
    public function index(Server $server): Response
    {
        return Inertia::render('servers/cron', [
            'server' => ['id' => $server->uuid, 'name' => $server->name],
            'cronJobs' => $server->cronJobs()
                ->with('application:id,name')
                ->orderBy('id')
                ->get(['id', 'application_id', 'user', 'command', 'schedule', 'status', 'last_run_at'])
                ->map(fn (CronJob $job) => [
                    ...$job->only(['id', 'application_id', 'user', 'command', 'schedule', 'status', 'last_run_at']),
                    'application_name' => $job->application?->name,
                ]),
            'applications' => $server->applications()->get(['id', 'name', 'linux_user']),
            'jobs' => $server->agentJobs()
                ->whereIn('type', ['render_config'])
                ->latest('id')
                ->limit(10)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
        ]);
    }

    public function store(Request $request, Server $server, CronService $service): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => [
                'nullable',
                'integer',
                Rule::exists('applications', 'id')->where('server_id', $server->id),
            ],
            'user' => ['required', 'string', 'max:32', 'regex:'.CronTemplates::USER_REGEX],
            'command' => ['required', 'string', 'max:1000', function ($attribute, $value, $fail) {
                if (str_contains($value, "\n")) {
                    $fail('The command must not contain newlines.');
                }
            }],
            'schedule' => ['required', 'string', 'regex:'.CronTemplates::SCHEDULE_REGEX],
        ]);

        $service->create($server, $validated, $request->user()->id);

        AuditLogger::log(
            action: 'cron.created',
            description: "Cron '{$validated['schedule']} {$validated['command']}' created",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['server_uuid' => $server->uuid],
        );

        return redirect()->route('cron.index', $server);
    }

    public function update(Request $request, CronJob $cronJob, CronService $service): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => [
                'nullable',
                'integer',
                Rule::exists('applications', 'id')->where('server_id', $cronJob->server_id),
            ],
            'user' => ['required', 'string', 'max:32', 'regex:'.CronTemplates::USER_REGEX],
            'command' => ['required', 'string', 'max:1000', function ($attribute, $value, $fail) {
                if (str_contains($value, "\n")) {
                    $fail('The command must not contain newlines.');
                }
            }],
            'schedule' => ['required', 'string', 'regex:'.CronTemplates::SCHEDULE_REGEX],
        ]);

        $service->update($cronJob, $validated, $request->user()->id);

        AuditLogger::log(
            action: 'cron.updated',
            description: 'Cron job updated',
            userId: $request->user()->id,
            serverId: $cronJob->server_id,
        );

        return redirect()->route('cron.index', $cronJob->server);
    }

    public function toggle(CronJob $cronJob, CronService $service): RedirectResponse
    {
        $service->toggle($cronJob, request()->user()->id);

        $status = $cronJob->fresh()->status ?? ($cronJob->status === 'active' ? 'paused' : 'active');

        AuditLogger::log(
            action: 'cron.toggled',
            description: "Cron job {$status}",
            userId: request()->user()->id,
            serverId: $cronJob->server_id,
            properties: ['status' => $status],
        );

        return redirect()->route('cron.index', $cronJob->server);
    }

    public function destroy(CronJob $cronJob, CronService $service): RedirectResponse
    {
        $server = $cronJob->server;
        $serverId = $cronJob->server_id;

        $service->delete($cronJob, request()->user()->id);

        AuditLogger::log(
            action: 'cron.deleted',
            description: 'Cron job deleted',
            userId: request()->user()->id,
            serverId: $serverId,
        );

        return redirect()->route('cron.index', $server);
    }
}
