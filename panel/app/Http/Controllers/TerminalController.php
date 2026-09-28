<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Provisioning\AppTemplates;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TerminalController extends Controller
{
    /**
     * Render the terminal page with a one-time session token.
     * Returns JSON when the request is AJAX (for reconnect without page reload).
     */
    public function show(Request $request, Server $server): Response|JsonResponse
    {
        $token = $this->generateToken($request, $server);
        $isReconnect = $request->wantsJson();

        // Every token issuance is a fresh shell grant (root for admins, the
        // webapp user for members), so all of them are logged — including
        // reconnects — with a property to tell them apart rather than
        // skipping reconnects and losing the record.
        AuditLogger::log(
            action: 'terminal.session_opened',
            description: ($isReconnect ? 'Terminal session reconnected on' : 'Terminal session opened on')." '{$server->name}'",
            userId: $request->user()->id,
            serverId: $server->id,
            properties: ['server_uuid' => $server->uuid, 'reconnect' => $isReconnect],
        );

        // AJAX request — return just the token (for reconnect without page reload).
        if ($isReconnect) {
            return response()->json([
                'terminalToken' => $token,
            ]);
        }

        // Available system users for the terminal user picker. Admins get the
        // full list; members are restricted to the webapp user only. This is
        // cosmetic convenience — the actual restriction is enforced on the
        // session token in auth() below, so editing the WebSocket `user`
        // query param cannot escalate a member to root.
        if ($request->user()->isAdmin()) {
            $systemUsers = $server->systemUsers()
                ->orderBy('username')
                ->pluck('username')
                ->prepend('root')
                ->unique()
                ->values();
        } else {
            $systemUsers = collect([AppTemplates::webappUser()]);
        }

        return Inertia::render('servers/terminal', [
            'server' => [
                ...$server->only(['name', 'public_ip', 'status']),
                'id' => $server->uuid,
            ],
            'terminalToken' => $token,
            'systemUsers' => $systemUsers,
            'gatewayUrl' => $this->gatewayWsUrl(),
        ]);
    }

    /**
     * Gateway callback: verify a terminal session token.
     * Called by the gateway's /terminal/connect handler.
     */
    public function auth(Request $request)
    {
        $serverUuid = $request->input('server_uuid');
        $sessionToken = $request->input('session_token');

        if (! $serverUuid || ! $sessionToken) {
            return response()->json(['valid' => false], 422);
        }

        $session = Cache::get("terminal:session:{$sessionToken}");

        if (! $session) {
            return response()->json(['valid' => false], 401);
        }

        // Verify the server UUID matches.
        if ($session['server_uuid'] !== $serverUuid) {
            return response()->json(['valid' => false], 403);
        }

        // Members may only open a PTY as the webapp user. The gateway
        // forwards the browser's `user` query param here — without this
        // check, editing the WebSocket URL would hand a member a root shell.
        // An absent/empty param means root on the agent side (terminal.go),
        // so it must fail closed for members too.
        $requestedUser = (string) $request->input('user', 'root') ?: 'root';
        if (! ($session['is_admin'] ?? false) && $requestedUser !== AppTemplates::webappUser()) {
            Cache::forget("terminal:session:{$sessionToken}");

            AuditLogger::log(
                action: 'terminal.session_rejected',
                description: "Terminal session rejected: member attempted to open a PTY as '{$requestedUser}'",
                userId: $session['user_id'] ?? null,
                serverId: $session['server_id'] ?? null,
                properties: ['server_uuid' => $serverUuid, 'requested_user' => $requestedUser],
            );

            return response()->json(['valid' => false], 403);
        }

        // Log before forgetting the cache entry — $session carries the user_id
        // of whoever called show() above; this is the gateway's confirmation
        // that a PTY is about to be opened for them.
        AuditLogger::log(
            action: 'terminal.session_verified',
            description: "Gateway verified a terminal session token as '{$requestedUser}'",
            userId: $session['user_id'] ?? null,
            serverId: $session['server_id'] ?? null,
            properties: ['server_uuid' => $serverUuid, 'user' => $requestedUser],
        );

        // Delete the token (single-use).
        Cache::forget("terminal:session:{$sessionToken}");

        $server = Server::where('uuid', $serverUuid)->first();

        return response()->json([
            'valid' => true,
            'server_id' => $server?->uuid,
        ]);
    }

    /**
     * Generate a one-time terminal session token.
     */
    private function generateToken(Request $request, Server $server): string
    {
        $token = Str::uuid()->toString();

        // is_admin rides in the session so auth() can enforce the
        // member-restricted webapp user without a DB round-trip.
        Cache::put("terminal:session:{$token}", [
            'server_uuid' => $server->uuid,
            'server_id' => $server->id,
            'user_id' => $request->user()->id,
            'is_admin' => $request->user()->isAdmin(),
        ], 60);

        return $token;
    }

    /**
     * Build the WebSocket URL for the gateway's terminal endpoint.
     */
    private function gatewayWsUrl(): string
    {
        $url = config('velink.gateway_public_url', env('GATEWAY_PUBLIC_URL', ''));

        // Normalize to ws/wss scheme.
        if (str_starts_with($url, 'wss://')) {
            return $url.'/terminal/connect';
        }
        if (str_starts_with($url, 'ws://')) {
            return $url.'/terminal/connect';
        }
        if (str_starts_with($url, 'https://')) {
            return 'wss://'.substr($url, 8).'/terminal/connect';
        }

        return $url.'/terminal/connect';
    }
}
