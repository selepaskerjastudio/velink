<?php

namespace App\Http\Controllers;

use App\Models\GitCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GitHubRepoController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => ['required', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $credential = GitCredential::where('uuid', $validated['credential'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($credential->provider->type !== 'github') {
            return response()->json(['repos' => []]);
        }

        $token = (string) $credential->access_token;
        $query = trim((string) ($validated['q'] ?? ''));

        if ($query === '') {
            $response = Http::withToken($token)
                ->withUserAgent('velink/1.0')
                ->get('https://api.github.com/user/repos', [
                    'sort' => 'updated',
                    'per_page' => 20,
                    'affiliation' => 'owner,collaborator,organization_member',
                ]);
        } else {
            $response = Http::withToken($token)
                ->withUserAgent('velink/1.0')
                ->get('https://api.github.com/search/repositories', [
                    'q' => $query,
                    'sort' => 'updated',
                    'per_page' => 15,
                ]);
        }

        if (! $response->successful()) {
            return response()->json(['repos' => [], 'error' => 'GitHub API error'], 502);
        }

        $items = $query === ''
            ? $response->json()
            : ($response->json('items') ?? []);

        $repos = collect($items)->map(fn ($r) => [
            'full_name' => $r['full_name'],
            'private' => $r['private'],
            'default_branch' => $r['default_branch'],
        ])->values();

        return response()->json(['repos' => $repos]);
    }
}
