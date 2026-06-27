<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TerminalController extends Controller
{
    /**
     * Render the terminal page with a one-time session token.
     */
    public function show(Request $request, Server $server): Response
    {
        // Generate a one-time terminal session token (60s TTL).
        $token = Str::uuid()->toString();
        Cache::put("terminal:session:{$token}", [
            'server_uuid' => $server->uuid,
            'server_id' => $server->id,
            'user_id' => $request->user()->id,
        ], 60);

        // Get available system users for the terminal user picker.
        $systemUsers = $server->systemUsers()
            ->orderBy('username')
            ->pluck('username')
            ->prepend('root')
            ->unique()
            ->values();

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

        // Delete the token (single-use).
        Cache::forget("terminal:session:{$sessionToken}");

        $server = Server::where('uuid', $serverUuid)->first();

        return response()->json([
            'valid' => true,
            'server_id' => $server?->uuid,
        ]);
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
