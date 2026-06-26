<?php

namespace App\Http\Controllers;

use App\Models\CloudflareToken;
use App\Services\AuditLogger;
use App\Services\CloudflareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CloudflareTokenController extends Controller
{
    public function __construct(private CloudflareService $cloudflare) {}

    /**
     * List the user's Cloudflare tokens.
     */
    public function index(Request $request): Response
    {
        $tokens = $request->user()->cloudflareTokens()
            ->latest('id')
            ->get(['id', 'uuid', 'email', 'verified_at', 'created_at'])
            ->map(fn (CloudflareToken $token) => [
                'id' => $token->uuid,
                'email' => $token->email,
                'verified' => $token->verified_at !== null,
                'created_at' => $token->created_at,
            ]);

        return Inertia::render('settings/cloudflare', [
            'tokens' => $tokens,
        ]);
    }

    /**
     * Validate the token against the Cloudflare API before storing it.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'api_token' => ['required', 'string', 'max:1024'],
        ]);

        // Verify the token before saving — reject invalid ones.
        $result = $this->cloudflare->verifyToken($validated['api_token']);
        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'api_token' => $result['message'] ?? 'Invalid Cloudflare API token.',
            ]);
        }

        $token = $request->user()->cloudflareTokens()->create([
            'email' => $validated['email'] ?? null,
            'api_token' => $validated['api_token'],
            'verified_at' => now(),
        ]);

        AuditLogger::log(
            action: 'cloudflare.token_added',
            description: 'Cloudflare API token added',
            userId: $request->user()->id,
            properties: ['email' => $validated['email'] ?? null],
        );

        return redirect()->route('cloudflare.index');
    }

    public function destroy(Request $request, CloudflareToken $cloudflareToken): RedirectResponse
    {
        abort_if($cloudflareToken->user_id !== $request->user()->id, 403);

        $cloudflareToken->delete();

        AuditLogger::log(
            action: 'cloudflare.token_deleted',
            description: 'Cloudflare API token removed',
            userId: $request->user()->id,
        );

        return redirect()->route('cloudflare.index');
    }
}
