<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Services\AuditLogger;
use App\Services\DeploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function github(Request $request, Application $application, DeploymentService $deploymentService): JsonResponse
    {
        if (! $this->verifyGitHubSignature($request, (string) $application->webhook_secret)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        if ($request->header('X-GitHub-Event') !== 'push') {
            return response()->json(['status' => 'ignored']);
        }

        $payload = $request->json()->all();
        $ref = $payload['ref'] ?? '';
        $pushedBranch = str_replace('refs/heads/', '', $ref);

        if ($pushedBranch !== $application->branch) {
            return response()->json(['status' => 'branch_mismatch']);
        }

        if (! $application->repository) {
            return response()->json(['error' => 'No repository configured'], 422);
        }

        $deploymentService->deploy($application, 'webhook');

        AuditLogger::log(
            action: 'application.deployed',
            description: "Deploy triggered for '{$application->name}' (webhook)",
            userId: null,
            serverId: $application->server_id,
            properties: [
                'branch' => $application->branch,
                'triggered_by' => 'webhook',
            ],
        );

        return response()->json(['status' => 'dispatched']);
    }

    private function verifyGitHubSignature(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        if (! $signature) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
