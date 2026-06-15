# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

**coruncloud** is a self-hosted server control panel (inspired by RunCloud/Laravel Forge) for managing multiple Ubuntu VMs from a single dashboard. It manages PHP applications (per-app PHP version), databases, Redis, systemd services, supervisord workers, cron jobs, and Git deployments — all without manual SSH.

## Repository structure (monorepo)

```
/panel       # Laravel 12 + Inertia 2 + React 19 (the control panel UI + backend)
/gateway     # Go realtime gateway (WebSocket bridge between agents and panel)
/agent       # Go agent binary installed on each managed server
/provision   # nginx/php-fpm/supervisor config templates + provisioning scripts
/installer   # curl|bash one-liner installer for the agent
/docs        # PRD.md, PLAN.md, TODO.md (architecture decisions + task tracking)
```

## Development commands

All commands run from `/panel`.

### Start everything (recommended)
```bash
composer run dev
```
Runs `php artisan serve`, `php artisan queue:listen`, `php artisan pail`, and `npm run dev` concurrently via `concurrently`.

### Individual services
```bash
php artisan serve              # Laravel HTTP server
php artisan queue:listen --tries=1
php artisan reverb:start       # WebSocket for browser (Reverb)
php artisan horizon            # Queue dashboard + worker
npm run dev                    # Vite dev server (HMR)
```

### Testing
```bash
php artisan test               # all Pest tests
php artisan test --filter TestName  # single test or class
php artisan test tests/Feature/ServerTest.php  # single file
```

### Frontend
```bash
npm run build          # production Vite build
npm run lint           # ESLint --fix
npm run format         # Prettier (resources/)
npm run format:check   # Prettier check only
```

### Gateway and agent (Go)
```bash
# From /gateway or /agent:
go build ./...
go vet ./...
go test ./...

# Run gateway in dev:
cp .env.example .env   # set GATEWAY_PANEL_SECRET = GATEWAY_SECRET from panel/.env
set -a && . ./.env && set +a
go run ./cmd/gateway
```

## Architecture

Three components communicate through Redis pub/sub:

```
Browser ←→ Reverb (WS) ←→ Laravel (panel)
                                │
                            Redis pub/sub
                                │
                          Gateway (Go) ←→ Agent (Go) [on each managed VM]
```

### Panel (Laravel) — `/panel`
The "brain": UI (Inertia + React), business logic, database, job orchestration, OAuth, webhooks, and audit log. Routes are split into per-resource files (`routes/servers.php`, `routes/applications.php`, etc.).

Key namespaces:
- `App\Models` — Eloquent models. External identifiers always use UUID (see below).
- `App\Services` — service layer (`JobDispatcher`, `GatewayInboundProcessor`, `DeploymentService`, etc.)
- `App\Provisioning` — `ProvisioningCatalog` (idempotent shell step recipes), template classes (`AppTemplates`, `WorkerTemplates`, `CronTemplates`, `DeployTemplates`)
- `App\Http\Controllers\Internal` — endpoints called by the gateway (not browser), e.g. `AgentVerificationController`

### Gateway (Go) — `/gateway`
A thin, stateless WebSocket terminator that runs on the panel VM. It:
- Accepts `wss://.../agent/connect` connections from agents (verifies token by calling the panel's internal `/internal/agent/verify` endpoint)
- Maintains agent presence in Redis (TTL-based, per-server key)
- Bridges messages: panel→agent (`coruncloud:gateway:dispatch`) and agent→panel (`coruncloud:gateway:inbound`)

### Agent (Go) — `/agent`
A single static binary installed on each managed server via the one-liner installer. It:
- Dials out to the gateway (no inbound connections needed — NAT/firewall friendly)
- Sends heartbeats and executes `AgentJob`s dispatched from the panel
- Three job types: `shell` (runs bash), `write_file` (writes a file), `render_config` (renders a Go `text/template` from a payload map)
- Streams stdout/stderr/exit-code back to the gateway, which forwards to the panel to update job state and broadcast progress to the browser via Reverb

### Job dispatch flow
1. Panel creates an `AgentJob` record (state: `pending`)
2. `JobDispatcher` publishes it to `coruncloud:gateway:dispatch` Redis channel
3. Gateway routes to the correct agent by `server_id` (UUID)
4. Agent executes, streams output chunks → gateway → `coruncloud:gateway:inbound`
5. `GatewayInboundProcessor` (panel) updates job status and broadcasts `AgentJobUpdated` via Reverb to the browser

## Key patterns

### UUID route keys
All models exposed in URLs or inter-service protocols (`Server`, `Application`, `GitCredential`, `Deployment`, `AgentJob`) use a `uuid` column as the route key via the `HasUuidRouteKey` trait (`app/Models/Concerns/HasUuidRouteKey.php`). Bigint `id` is used only for internal DB relations/FKs. **Never expose raw `id` in URLs, Inertia props, broadcast channels, or agent↔gateway headers.**

### Protocol constants
`App\Support\GatewayProtocol` (PHP) and `gateway/internal/protocol/protocol.go` (Go) must stay in sync. Redis channel names, message type constants, and header names are defined there.

### Config templates
Nginx vhosts and php-fpm pool configs are Go `text/template` strings defined in `App\Provisioning\AppTemplates`. The agent renders them via `render_config` jobs using a flat `snake_case` variable map. The php-fpm socket is keyed by `linux_user` (not `php_version`), so changing PHP version only moves the pool conf without touching the nginx vhost.

### Encrypted secrets
Git tokens, DB passwords, and `.env` file contents are stored with Laravel's encrypted cast. Never store these as plaintext.

## Database

PostgreSQL is the primary store. SQLite is used in dev/testing (default `.env.example`). Migrations live in `panel/database/migrations/`.

## Model guidance (Sonnet vs Opus)

Per `docs/PLAN.md` §8 and `docs/TODO.md`:

- 🟢 **Sonnet is sufficient** for most UI work, CRUD controllers, migrations, frontend pages, config templates (Phases 0, 2, 4).
- 🔴 **Switch to Opus** for security-critical or architecturally complex tasks: Gateway Go internals, the agent transport layer, privileged execution logic, zero-downtime deploy engine, web terminal (PTY), and idempotent provisioning. **Remind the user to switch to Opus before starting any 🔴 task.**

## Current status (as of 2026-06-15)

Phases 0–4 are substantially complete. Remaining work:
- Fase 2: SSL/Let's Encrypt (`certbot`), end-to-end verification
- Fase 3: Zero-downtime deploy (🔴 Opus), webhook auto-deploy endpoint, deploy key/OAuth integration
- Fase 5: Monitoring (metrics collection + charts), web terminal (🔴 Opus)
- Cross-cutting: audit log, allowlist/job templates, mTLS, reinstall reminder for agents with old bigint server IDs

## Testing notes

Tests use Pest and are in `panel/tests/Feature/`. The test suite hits a SQLite in-memory database. Run `php artisan test` from `/panel`. Gateway and agent have Go unit tests; run `go test ./...` from `/gateway` and `/agent` respectively.
