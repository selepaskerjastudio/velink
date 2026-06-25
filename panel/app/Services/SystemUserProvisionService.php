<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\SystemUser;
use Illuminate\Support\Str;

/**
 * Provisions OS login accounts on a managed server via agent shell jobs.
 *
 * System users are SSH-accessible accounts kept separate from the shared
 * `velink` webapp user (which has a nologin shell). SSH keys are deployed to
 * a system user's authorized_keys (see SshKeyService).
 *
 * Usernames are validated upstream by the controller against USERNAME_REGEX;
 * they are therefore safe to interpolate into shell commands.
 */
class SystemUserProvisionService
{
    /** Allowlist for OS usernames (validated in the controller before dispatch). */
    public const USERNAME_REGEX = '/^[a-z_][a-z0-9_-]{0,31}$/';

    /** Shells the panel will offer. */
    public const ALLOWED_SHELLS = ['/bin/bash', '/bin/sh', '/usr/sbin/nologin'];

    public function __construct(private JobDispatcher $dispatcher) {}

    /**
     * Create the OS account with an initial locked password and optional sudo.
     *
     * @return array{systemUser: SystemUser, job: AgentJob}
     */
    public function create(Server $server, string $username, string $shell, bool $isSudo, ?int $userId = null): array
    {
        $systemUser = SystemUser::create([
            'server_id' => $server->id,
            'username' => $username,
            'shell' => $shell,
            'is_sudo' => $isSudo,
        ]);

        // A random initial password is set and immediately locked via
        // `passwd -l` so the account is key-only until someone sets one.
        $initialPassword = Str::password(24, symbols: false);

        $command = "useradd --create-home --shell {$shell} {$username}\n";
        $command .= "echo '{$username}:{$initialPassword}' | chpasswd\n";
        $command .= "passwd -l {$username}";
        if ($isSudo) {
            $command .= "\nusermod -aG sudo {$username}";
        }

        $job = $this->dispatchShell($server, "Create system user {$username}", $command, $userId);

        return ['systemUser' => $systemUser, 'job' => $job];
    }

    /**
     * Toggle sudo membership for an existing user.
     */
    public function updateSudo(SystemUser $systemUser, bool $isSudo, ?int $userId = null): AgentJob
    {
        $username = $systemUser->username;
        $command = $isSudo
            ? "gpasswd -a {$username} sudo"
            : "gpasswd -d {$username} sudo";

        $job = $this->dispatchShell(
            $systemUser->server,
            $isSudo ? "Grant sudo to {$username}" : "Revoke sudo from {$username}",
            $command,
            $userId,
        );

        $systemUser->forceFill(['is_sudo' => $isSudo])->save();

        return $job;
    }

    /**
     * Change the user's login shell.
     */
    public function updateShell(SystemUser $systemUser, string $shell, ?int $userId = null): AgentJob
    {
        $username = $systemUser->username;
        $command = "chsh -s {$shell} {$username}";

        $job = $this->dispatchShell(
            $systemUser->server,
            "Set shell for {$username}",
            $command,
            $userId,
        );

        $systemUser->forceFill(['shell' => $shell])->save();

        return $job;
    }

    /**
     * Remove the OS account and its home directory.
     */
    public function delete(SystemUser $systemUser, ?int $userId = null): AgentJob
    {
        $username = $systemUser->username;
        $server = $systemUser->server;

        $job = $this->dispatchShell(
            $server,
            "Delete system user {$username}",
            "userdel --remove {$username}",
            $userId,
        );

        $systemUser->delete();

        return $job;
    }

    private function dispatchShell(Server $server, string $label, string $command, ?int $userId): AgentJob
    {
        return $this->dispatcher->dispatch($server, 'shell', [
            'command' => "set -e\necho \"==> {$label}\"\n{$command}",
            'timeout' => 60,
        ], ['user_id' => $userId, 'label' => $label]);
    }
}
