<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\SystemUser;
use App\Services\AuditLogger;
use App\Services\SystemUserProvisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SystemUserController extends Controller
{
    public function __construct(private SystemUserProvisionService $service) {}

    /**
     * List the OS login accounts on a server.
     */
    public function index(Request $request, Server $server): Response
    {
        // Auto-register the webapp user as a deployable SystemUser when apps exist.
        if ($server->applications()->exists()) {
            app(\App\Services\SshKeyService::class)->ensureWebappUser($server, $request->user()->id);
        }

        $systemUsers = $server->systemUsers()
            ->withCount('sshKeys')
            ->orderByRaw('is_system_reserved desc')
            ->orderBy('username')
            ->get(['id', 'uuid', 'username', 'shell', 'is_sudo', 'is_system_reserved'])
            ->map(fn (SystemUser $user) => [
                'id' => $user->uuid,
                'username' => $user->username,
                'shell' => $user->shell,
                'is_sudo' => $user->is_sudo,
                'is_system_reserved' => $user->is_system_reserved,
                'ssh_keys_count' => $user->ssh_keys_count,
            ]);

        return Inertia::render('servers/system-users', [
            'server' => [
                ...$server->only(['name', 'public_ip', 'status']),
                'id' => $server->uuid,
            ],
            'systemUsers' => $systemUsers,
            'allowedShells' => SystemUserProvisionService::ALLOWED_SHELLS,
        ]);
    }

    /**
     * Create a new OS login account.
     *
     * @throws ValidationException
     */
    public function store(Request $request, Server $server): RedirectResponse
    {
        $validated = $request->validate([
            'username' => [
                'required', 'string', 'max:32',
                'regex:'.SystemUserProvisionService::USERNAME_REGEX,
                Rule::unique('system_users')->where(fn ($q) => $q->where('server_id', $server->id)),
            ],
            'shell' => ['required', 'string', Rule::in(SystemUserProvisionService::ALLOWED_SHELLS)],
            'is_sudo' => ['boolean'],
        ]);

        $result = $this->service->create(
            server: $server,
            username: $validated['username'],
            shell: $validated['shell'],
            isSudo: $validated['is_sudo'],
            userId: $request->user()->id,
        );

        AuditLogger::log(
            action: 'system_user.created',
            description: "System user '{$validated['username']}' created on '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['username' => $validated['username'], 'sudo' => $validated['is_sudo']],
        );

        return redirect()->route('system-users.index', $server);
    }

    public function updateSudo(Request $request, SystemUser $systemUser): RedirectResponse
    {
        $validated = $request->validate([
            'is_sudo' => ['required', 'boolean'],
        ]);

        $this->service->updateSudo($systemUser, $validated['is_sudo'], $request->user()->id);

        AuditLogger::log(
            action: 'system_user.sudo_toggled',
            description: "Sudo for '{$systemUser->username}' ".($validated['is_sudo'] ? 'granted' : 'revoked'),
            userId: $request->user()->id,
            serverId: $systemUser->server_id,
        );

        return redirect()->route('system-users.index', $systemUser->server);
    }

    public function updateShell(Request $request, SystemUser $systemUser): RedirectResponse
    {
        $validated = $request->validate([
            'shell' => ['required', 'string', Rule::in(SystemUserProvisionService::ALLOWED_SHELLS)],
        ]);

        $this->service->updateShell($systemUser, $validated['shell'], $request->user()->id);

        AuditLogger::log(
            action: 'system_user.shell_changed',
            description: "Shell for '{$systemUser->username}' set to '{$validated['shell']}'",
            userId: $request->user()->id,
            serverId: $systemUser->server_id,
        );

        return redirect()->route('system-users.index', $systemUser->server);
    }

    public function destroy(Request $request, SystemUser $systemUser): RedirectResponse
    {
        if ($systemUser->is_system_reserved) {
            abort(403, 'System-reserved users cannot be deleted.');
        }

        $server = $systemUser->server;
        $username = $systemUser->username;

        $this->service->delete($systemUser, $request->user()->id);

        AuditLogger::log(
            action: 'system_user.deleted',
            description: "System user '{$username}' deleted from '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
        );

        return redirect()->route('system-users.index', $server);
    }
}
