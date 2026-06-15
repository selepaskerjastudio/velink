<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies an agent's token on behalf of the gateway. The token is stored as a
 * bcrypt hash on the server record, so verification cannot happen in the
 * gateway — it lives here, behind the shared-secret middleware.
 */
class AgentVerificationController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => ['required', 'uuid'],
            'token' => ['required', 'string'],
        ]);

        $server = Server::where('uuid', $data['server_id'])->first();

        if (! $server || ! Hash::check($data['token'], $server->agent_token)) {
            return response()->json(['valid' => false], 401);
        }

        return response()->json([
            'valid' => true,
            'server' => [
                'id' => $server->uuid,
                'name' => $server->name,
                'status' => $server->status,
            ],
        ]);
    }
}
