<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\DnsRecord;
use App\Services\AuditLogger;
use App\Services\CloudflareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DnsRecordController extends Controller
{
    public function __construct(private CloudflareService $cloudflare) {}

    /**
     * List DNS records for an application (both panel-tracked and live from CF).
     */
    public function index(Request $request, Application $application): Response
    {
        $token = $request->user()->cloudflareTokens()->latest('id')->first();

        $dnsRecords = $application->dnsRecords()
            ->latest('id')
            ->get(['id', 'uuid', 'type', 'name', 'content', 'proxied', 'ttl'])
            ->map(fn (DnsRecord $r) => [
                'id' => $r->uuid,
                'type' => $r->type,
                'name' => $r->name,
                'content' => $r->content,
                'proxied' => $r->proxied,
                'ttl' => $r->ttl,
            ]);

        return Inertia::render('apps/dns', [
            'application' => [
                'id' => $application->uuid,
                'name' => $application->name,
                'domain' => $application->domain,
            ],
            'server' => [
                'id' => $application->server->uuid,
                'name' => $application->server->name,
                'public_ip' => $application->server->public_ip,
            ],
            'dnsRecords' => $dnsRecords,
            'hasCloudflareToken' => $token !== null,
        ]);
    }

    /**
     * Create a DNS record via Cloudflare API and track it in the DB.
     *
     * @throws ValidationException
     */
    public function store(Request $request, Application $application): RedirectResponse
    {
        $token = $request->user()->cloudflareTokens()->latest('id')->first();
        if (! $token) {
            throw ValidationException::withMessages([
                'type' => 'Connect a Cloudflare account in Settings first.',
            ]);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(['A', 'AAAA', 'CNAME', 'TXT'])],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:255'],
            'proxied' => ['boolean'],
        ]);

        $zoneId = $this->cloudflare->findZoneForDomain($token->api_token, $validated['name']);
        if (! $zoneId) {
            throw ValidationException::withMessages([
                'name' => "No Cloudflare zone found for {$validated['name']}.",
            ]);
        }

        $recordId = $this->cloudflare->createRecord($token->api_token, $zoneId, [
            'type' => $validated['type'],
            'name' => $validated['name'],
            'content' => $validated['content'],
            'proxied' => $validated['proxied'] ?? false,
            'ttl' => 1,
        ]);

        if (! $recordId) {
            throw ValidationException::withMessages([
                'content' => 'Cloudflare rejected the record. Check the values.',
            ]);
        }

        DnsRecord::create([
            'application_id' => $application->id,
            'cloudflare_token_id' => $token->id,
            'zone_id' => $zoneId,
            'record_id' => $recordId,
            'type' => $validated['type'],
            'name' => $validated['name'],
            'content' => $validated['content'],
            'proxied' => $validated['proxied'] ?? false,
            'ttl' => 1,
        ]);

        AuditLogger::log(
            action: 'dns.record_created',
            description: "DNS record created: {$validated['type']} {$validated['name']}",
            userId: $request->user()->id,
            properties: $validated,
        );

        return redirect()->route('dns.index', $application);
    }

    /**
     * Delete a DNS record from Cloudflare and the DB.
     */
    public function destroy(Request $request, Application $application, DnsRecord $dnsRecord): RedirectResponse
    {
        abort_if($dnsRecord->application_id !== $application->id, 404);

        $token = $request->user()->cloudflareTokens()->latest('id')->first();

        if ($token) {
            $this->cloudflare->deleteRecord($token->api_token, $dnsRecord->zone_id, $dnsRecord->record_id);
        }

        $name = $dnsRecord->name;
        $dnsRecord->delete();

        AuditLogger::log(
            action: 'dns.record_deleted',
            description: "DNS record deleted: {$name}",
            userId: $request->user()->id,
        );

        return redirect()->route('dns.index', $application);
    }
}
