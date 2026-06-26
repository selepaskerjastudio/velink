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

        $deployment = $deploymentService->deploy($application, 'webhook');

        // The concurrency guard may skip the deploy if one is already running.
        if ($deployment->status === 'failed') {
            return response()->json(['status' => 'skipped_concurrent']);
        }

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

    public function gitlab(Request $request, Application $application, DeploymentService $deploymentService): JsonResponse
    {
        $token = $request->header('X-Gitlab-Token', '');
        if (! $token || ! hash_equals((string) $application->webhook_secret, $token)) {
            return response()->json(['error' => 'Invalid token'], 403);
        }

        if ($request->header('X-Gitlab-Event') !== 'Push Hook') {
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

        $deployment = $deploymentService->deploy($application, 'webhook');

        if ($deployment->status === 'failed') {
            return response()->json(['status' => 'skipped_concurrent']);
        }

        AuditLogger::log(
            action: 'application.deployed',
            description: "Deploy triggered for '{$application->name}' (gitlab webhook)",
            userId: null,
            serverId: $application->server_id,
            properties: [
                'branch' => $application->branch,
                'triggered_by' => 'gitlab_webhook',
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
