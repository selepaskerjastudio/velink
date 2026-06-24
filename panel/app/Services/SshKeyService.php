<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\SshKey;

/**
 * Pure-PHP SSH public key handling plus deployment orchestration.
 *
 * Keys are written to a dedicated `velink-admin` OS user per server — kept
 * separate from the shared `velink` webapp user (which has a nologin shell)
 * so SSH access never intersects with the php-fpm runtime.
 *
 * On every deploy/revoke the server's authorized_keys is REWRITTEN in full
 * from the pivot table, making the DB the single source of truth for who can
 * log in. Each sync is a three-job sequence: ensure the admin user exists,
 * write the file, then fix ownership/perms (sshd StrictModes compliance).
 */
class SshKeyService
{
    /** The dedicated SSH-login OS user, distinct from the webapp user. */
    public const ADMIN_USER = 'velink-admin';

    /** SSH home directory for the admin user. */
    public const ADMIN_HOME = '/home/velink-admin';

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

        // Drop a leading "no-..." options field (e.g. from authorized_keys) —
        // a bare pasted key never has one, but be defensive.
        if (str_starts_with($key, 'no-')) {
            // Skip the options token; the first whitespace-separated token that
            // starts with a known type prefix is the real key.
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

        // Validate that the decoded blob's embedded type matches the prefix.
        // OpenSSH blobs start with a 4-byte length followed by the type string.
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
     *
     * Matches `ssh-keygen -lf` output. Implemented in pure PHP so the panel
     * does not depend on the ssh-keygen binary being present.
     */
    public function computeFingerprint(string $key): string
    {
        $parsed = $this->parsePublicKey($key);
        $blob = base64_decode($parsed['blob'], true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Deploy a key to a server: record the pivot and rewrite authorized_keys.
     *
     * @return array<int, AgentJob> The dispatched jobs.
     */
    public function deployToServer(SshKey $key, Server $server, ?int $userId = null): array
    {
        // Idempotent attach: attaching again would violate the unique pivot
        // index, so use syncWithoutDetaching which is a no-op when present.
        $server->sshKeys()->syncWithoutDetaching([
            $key->id => ['deployed_at' => now()],
        ]);

        return $this->syncServerKeys($server, $userId);
    }

    /**
     * Rebuild the admin user's authorized_keys from every key in the pivot.
     *
     * This is the single write path to the file — deploy and revoke both go
     * through it so the server always reflects the DB exactly.
     *
     * @return array<int, AgentJob>
     */
    public function syncServerKeys(Server $server, ?int $userId = null): array
    {
        $dispatcher = app(JobDispatcher::class);

        $keys = $server->sshKeys()
            ->orderBy('name')
            ->pluck('public_key')
            ->implode("\n");

        $user = self::ADMIN_USER;
        $home = self::ADMIN_HOME;
        $authKeys = "{$home}/.ssh/authorized_keys";
        $attrs = ['user_id' => $userId, 'label' => 'Sync SSH authorized_keys'];

        // 1. Ensure the dedicated admin user exists (idempotent). It gets sudo
        //    so the operator can actually administer the box once logged in.
        $jobs[] = $dispatcher->dispatch($server, 'shell', [
            'command' => "id -u {$user} >/dev/null 2>&1 || useradd --create-home --shell /bin/bash --groups sudo {$user}",
        ], $attrs + ['label' => "Ensure {$user} user"]);

        // 2. Rewrite the whole authorized_keys from the DB.
        $jobs[] = $dispatcher->dispatch($server, 'write_file', [
            'path' => $authKeys,
            'content' => $keys.($keys === '' ? '' : "\n"),
            'mode' => '0600',
        ], $attrs + ['label' => 'Write authorized_keys']);

        // 3. Fix ownership and tighten perms — sshd StrictModes rejects a
        //    root-owned authorized_keys in a non-root home.
        $jobs[] = $dispatcher->dispatch($server, 'shell', [
            'command' => "mkdir -p {$home}/.ssh && chown -R {$user}:{$user} {$home}/.ssh && chmod 700 {$home}/.ssh && chmod 600 {$authKeys}",
        ], $attrs + ['label' => "Fix {$user} SSH permissions"]);

        return $jobs;
    }
}
