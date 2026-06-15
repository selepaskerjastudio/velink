<?php

namespace App\Http\Controllers;

use App\Models\DatabaseInstance;
use App\Models\Server;
use App\Services\DatabaseProvisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseInstanceController extends Controller
{
    private const NAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    private const CHARSET_REGEX = '/^[A-Za-z0-9_]+$/';

    /**
     * Reserved/system database names, checked case-insensitively, across all
     * supported engines (MySQL/MariaDB, PostgreSQL, MongoDB).
     */
    private const RESERVED_NAMES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
        'postgres',
        'template0',
        'template1',
        'admin',
        'local',
        'config',
    ];

    public function index(Server $server): Response
    {
        return Inertia::render('servers/databases', [
            'server' => ['id' => $server->uuid, 'name' => $server->name],
            'databases' => $server->databases()
                ->orderBy('name')
                ->get(['id', 'engine', 'name', 'charset', 'collation']),
            'jobs' => $server->agentJobs()
                ->where('type', 'shell')
                ->latest('id')
                ->limit(20)
                ->get(['uuid', 'type', 'label', 'status', 'exit_code', 'output', 'created_at'])
                ->reverse()
                ->values(),
        ]);
    }

    public function store(Request $request, Server $server, DatabaseProvisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'engine' => ['required', 'string', Rule::in(['mysql', 'mariadb', 'postgres', 'mongodb'])],
            'name' => [
                'required',
                'string',
                'regex:'.self::NAME_REGEX,
                function ($attribute, $value, $fail) {
                    if (in_array(strtolower((string) $value), self::RESERVED_NAMES, true)) {
                        $fail('The '.$attribute.' is a reserved name.');
                    }
                },
                Rule::unique('databases', 'name')->where('server_id', $server->id),
            ],
            'charset' => ['nullable', 'string', 'max:64', 'regex:'.self::CHARSET_REGEX],
            'collation' => ['nullable', 'string', 'max:64', 'regex:'.self::CHARSET_REGEX],
        ]);

        $service->create(
            $server,
            $validated['engine'],
            $validated['name'],
            $validated['charset'] ?? null,
            $validated['collation'] ?? null,
            $request->user()->id,
        );

        return redirect()->route('databases.index', $server);
    }

    public function destroy(DatabaseInstance $database, DatabaseProvisionService $service): RedirectResponse
    {
        $server = $database->server;

        $service->delete($database, request()->user()->id);

        return redirect()->route('databases.index', $server);
    }
}
