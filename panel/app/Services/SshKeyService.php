<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\SystemUser;
use App\Provisioning\AppTemplates;
use Illuminate\Support\Facades\DB;

/**
 * Pure-PHP SSH public key handling plus deployment orchestration.
 *
 * Keys are deployed to a {@see SystemUser}'s authorized_keys. Each system user
 * keeps its own authorized_keys file under /home/{username}/.ssh, so a key may
 * be deployed to multiple users on the same server. On every deploy/revoke the
 * affected user's authorized_keys is REWRITTEN in full from the pivot table,
 * making the DB the single source of truth for who can log in.
 */
class SshKeyService
{
    /** The default admin username materialised for servers that predate this feature. */
    public const DEFAULT_ADMIN_USERNAME = 'velink-admin';

    /** Key types we accept, mapped to nothing (only used for validation). */
    private const ALLOWED_TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ecdsa-sha2-nistp256@openssh.com',
        'sk-ssh-ed25519@openssh.com',
    ];

    /**
     * Parse an OpenSSH public key line into its components.
     *
     * @return array{type: string, blob: string, comment: ?string}
     *
     * @throws \InvalidArgumentException when the key is malformed.
     */
    public function parsePublicKey(string $key): array
    {
        $key = trim($key);

        if ($key === '') {
            throw new \InvalidArgumentException('The public key is empty.');
        }

        if (str_starts_with($key, 'no-')) {
            $parts = preg_split('/\s+/', $key);
            while ($parts && ! in_array($parts[0], self::ALLOWED_TYPES, true)) {
                array_shift($parts);
            }
            $key = implode(' ', $parts);
        }

        $parts = preg_split('/\s+/', $key, 3);
        if ($parts === false || count($parts) < 2) {
            throw new \InvalidArgumentException('The public key must have a type and a base64 blob.');
        }

        [$type, $blob] = $parts;
        $comment = $parts[2] ?? null;
        $comment = ($comment === null || $comment === '') ? null : $comment;

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported SSH key type: {$type}.");
        }

        $decoded = base64_decode($blob, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('The public key blob is not valid base64.');
        }

        if (strlen($decoded) < 4) {
            throw new \InvalidArgumentException('The public key blob is truncated.');
        }
        $len = unpack('N', substr($decoded, 0, 4))[1];
        $embeddedType = substr($decoded, 4, $len);
        if ($embeddedType !== $type) {
            throw new \InvalidArgumentException('The public key blob does not match its declared type.');
        }

        return ['type' => $type, 'blob' => $blob, 'comment' => $comment];
    }

    /**
     * Compute the OpenSSH-format fingerprint ("SHA256:...") for a public key.
     */
    public function computeFingerprint(string $key): string
    {
        $parsed = $this->parsePublicKey($key);
        $blob = base64_decode($parsed['blob'], true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Ensure a default admin SystemUser exists for a server and return it.
     * Used for backward compatibility when no target user is specified.
     */
    public function ensureDefaultAdmin(Server $server): SystemUser
    {
        return SystemUser::firstOrCreate(
            ['server_id' => $server->id, 'username' => self::DEFAULT_ADMIN_USERNAME],
            ['shell' => '/bin/bash', 'is_sudo' => true, 'is_system_reserved' => true],
        );
    }

    /**
     * Ensure the shared webapp OS user (the one that owns /home/{user}/webapps/)
     * is registered as a deployable SystemUser, and flip its login shell to bash.
     *
     * This is the RunCloud model: the webapp user IS the SSH user, so you can
     * SSH in and manage application files directly. Only called when the server
     * has at least one application (meaning the webapp OS user exists on the box).
     *
     * @param  ?int  $userId  Panel user triggering the shell flip (for job labeling).
     */
    public function ensureWebappUser(Server $server, ?int $userId = null): SystemUser
    {
        $username = AppTemplates::webappUser();

        $user = SystemUser::firstOrCreate(
            ['server_id' => $server->id, 'username' => $username],
            ['shell' => '/bin/bash', 'is_sudo' => false, 'is_system_reserved' => true],
        );

        // Flip the shell to bash. On existing servers the user was created with
        // nologin; on new servers it's already bash. chsh is idempotent — safe
        // to run unconditionally. Only dispatched the first time (newly created
        // row) to avoid redundant jobs on every page load.
        if ($user->wasRecentlyCreated) {
            app(JobDispatcher::class)->dispatch($server, 'shell', [
                'command' => "chsh -s /bin/bash {$username}",
            ], ['user_id' => $userId, 'label' => "Enable SSH login for {$username}"]);
        }

        return $user;
    }

    /**
     * Deploy a key to a specific system user: record the pivot and rewrite
     * that user's authorized_keys.
     *
     * @param  ?int  $userId  Panel user who triggered the deploy (for audit/job labeling).
     * @return array<int, AgentJob>
     */
    public function deployToServer(SshKey $key, Server $server, SystemUser $targetUser, ?int $userId = null): array
    {
        // Idempotent attach scoped to the target user — the same key may target
        // several different users on this server.
        DB::table('server_ssh_key')->updateOrInsert(
            [
                'server_id' => $server->id,
                'ssh_key_id' => $key->id,
                'system_user_id' => $targetUser->id,
            ],
            ['deployed_at' => now()],
        );

        return $this->syncUserKeys($server, $targetUser, $userId);
    }

    /**
     * Revoke a key from a specific user: detach the pivot row and rebuild
     * that user's authorized_keys.
     *
     * @return array<int, AgentJob>
     */
    public function revokeFromUser(SshKey $key, Server $server, SystemUser $targetUser, ?int $userId = null): array
    {
        DB::table('server_ssh_key')->where([
            'server_id' => $server->id,
            'ssh_key_id' => $key->id,
            'system_user_id' => $targetUser->id,
        ])->delete();

        return $this->syncUserKeys($server, $targetUser, $userId);
    }

    /**
     * Rebuild the authorized_keys for every system user on a server that has
     * any key deployed. Used by SshKeyController::destroy when a key is deleted
     * from the account entirely and every affected user must be refreshed.
     *
     * @return array<int, AgentJob>
     */
    public function syncServerKeys(Server $server, ?int $userId = null): array
    {
        $jobs = [];
        $userIds = DB::table('server_ssh_key')
            ->where('server_id', $server->id)
            ->whereNotNull('system_user_id')
            ->pluck('system_user_id')
            ->unique();

        foreach ($userIds as $systemUserId) {
            $targetUser = SystemUser::find($systemUserId);
            if ($targetUser) {
                $jobs = array_merge($jobs, $this->syncUserKeys($server, $targetUser, $userId));
            }
        }

        return $jobs;
    }

    /**
     * Rewrite a single system user's authorized_keys from the pivot.
     *
     * This is the single write path to the file — deploy, revoke, and
     * syncServerKeys all go through it so the file always reflects the DB.
     *
     * @return array<int, AgentJob>
     */
    public function syncUserKeys(Server $server, SystemUser $targetUser, ?int $userId = null): array
    {
        $dispatcher = app(JobDispatcher::class);

        $keys = DB::table('server_ssh_key')
            ->where('server_id', $server->id)
            ->where('system_user_id', $targetUser->id)
            ->join('ssh_keys', 'ssh_keys.id', '=', 'server_ssh_key.ssh_key_id')
            ->orderBy('ssh_keys.name')
            ->pluck('ssh_keys.public_key')
            ->implode("\n");

        $user = $targetUser->username;
        $home = "/home/{$user}";
        $authKeys = "{$home}/.ssh/authorized_keys";
        $attrs = ['user_id' => $userId, 'label' => 'Sync SSH authorized_keys'];

        $jobs = [];

        // 1. Ensure the user can log in (it may be a freshly-created account).
        $jobs[] = $dispatcher->dispatch($server, 'shell', [
            'command' => "id -u {$user} >/dev/null 2>&1 || useradd --create-home --shell {$targetUser->shell} {$user}",
        ], $attrs + ['label' => "Ensure {$user} user"]);

        // 2. Rewrite the whole authorized_keys from the DB.
        $jobs[] = $dispatcher->dispatch($server, 'write_file', [
            'path' => $authKeys,
            'content' => $keys.($keys === '' ? '' : "\n"),
            'mode' => '0600',
        ], $attrs + ['label' => "Write {$user} authorized_keys"]);

        // 3. Fix ownership and tighten perms for sshd StrictModes compliance.
        $jobs[] = $dispatcher->dispatch($server, 'shell', [
            'command' => "mkdir -p {$home}/.ssh && chown -R {$user}:{$user} {$home}/.ssh && chmod 700 {$home}/.ssh && chmod 600 {$authKeys}",
        ], $attrs + ['label' => "Fix {$user} SSH permissions"]);

        return $jobs;
    }
}
