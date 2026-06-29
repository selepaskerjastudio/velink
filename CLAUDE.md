# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> See also [AGENTS.md](AGENTS.md) — the concise, tool-agnostic agent guide (commands + hard rules). Keep AGENTS.md and this file in sync when either changes.

## What this project is

**Velink** is a self-hosted server control panel (inspired by RunCloud/Laravel Forge) for managing multiple Ubuntu VMs from a single dashboard. It manages PHP applications (per-app PHP version), databases, Redis, systemd services, supervisord workers, cron jobs, and Git deployments — all without manual SSH.

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
- `App\Services` — service layer. Job/transport: `JobDispatcher`, `GatewayInboundProcessor`, `DeploymentService`. Feature services: `BackupService`, `CloudflareService` + `DnsService` (Cloudflare DNS), `FirewallService` + `Fail2BanService` (server security), `ThresholdChecker` (metric alerts), `DiscordWebhookService` + `TelegramService` (notification channels), `AnsiStripper` (deploy-log rendering).
- `App\Provisioning` — `ProvisioningCatalog` (idempotent shell step recipes), template classes (`AppTemplates`, `WorkerTemplates`, `CronTemplates`, `DeployTemplates`)
- `App\Events` + `App\Listeners` + `App\Notifications` — server-alert pipeline: `ServerAlertTriggered`/`ServerAlertResolved` events → `SendAlertNotifications` (queued listener) → `ServerAlertNotification`.
- `App\Http\Controllers\Internal` — endpoints called by the gateway (not browser), e.g. `AgentVerificationController`

Routes are one file per resource under `routes/`, all `require`d from `routes/web.php`. Newer ones: `security.php` (firewall/fail2ban), `cloudflare.php` (token + DNS records), `notifications.php`, `backups.php`, `webhooks.php`, `ssh-keys.php`, `system-users.php`.

### Gateway (Go) — `/gateway`
A thin, stateless WebSocket terminator that runs on the panel VM. It:
- Accepts `wss://.../agent/connect` connections from agents (verifies token by calling the panel's internal `/internal/agent/verify` endpoint)
- Maintains agent presence in Redis (TTL-based, per-server key)
- Bridges messages: panel→agent (`velink:gateway:dispatch`) and agent→panel (`velink:gateway:inbound`)

### Agent (Go) — `/agent`
A single static binary installed on each managed server via the one-liner installer. It:
- Dials out to the gateway (no inbound connections needed — NAT/firewall friendly)
- Sends heartbeats and executes `AgentJob`s dispatched from the panel
- Three job types: `shell` (runs bash), `write_file` (writes a file), `render_config` (renders a Go `text/template` from a payload map)
- Streams stdout/stderr/exit-code back to the gateway, which forwards to the panel to update job state and broadcast progress to the browser via Reverb

### Job dispatch flow
1. Panel creates an `AgentJob` record (state: `pending`)
2. `JobDispatcher` publishes it to `velink:gateway:dispatch` Redis channel
3. Gateway routes to the correct agent by `server_id` (UUID)
4. Agent executes, streams output chunks → gateway → `velink:gateway:inbound`
5. `GatewayInboundProcessor` (panel) updates job status and broadcasts `AgentJobUpdated` via Reverb to the browser

## Key patterns

### UUID route keys
All models exposed in URLs or inter-service protocols (`Server`, `Application`, `GitCredential`, `Deployment`, `AgentJob`, `DatabaseInstance`, `DatabaseUser`) use a `uuid` column as the route key via the `HasUuidRouteKey` trait (`app/Models/Concerns/HasUuidRouteKey.php`). Bigint `id` is used only for internal DB relations/FKs. **Never expose raw `id` in URLs, Inertia props, broadcast channels, or agent↔gateway headers.**

### Protocol constants
`App\Support\GatewayProtocol` (PHP) and `gateway/internal/protocol/protocol.go` (Go) must stay in sync. Redis channel names, message type constants, and header names are defined there.

### Config templates
Nginx vhosts and php-fpm pool configs are Go `text/template` strings defined in `App\Provisioning\AppTemplates`. The agent renders them via `render_config` jobs using a flat `snake_case` variable map. The php-fpm socket is keyed by `linux_user` (not `php_version`), so changing PHP version only moves the pool conf without touching the nginx vhost.

### Encrypted secrets
Git tokens, DB passwords, `.env` file contents, Cloudflare API tokens, notification webhook URLs/bot tokens, and webhook secrets are stored with Laravel's encrypted cast. Never store these as plaintext. Also add secret-bearing columns to the model's `$hidden` (e.g. `Application::$webhook_secret`) so they never leak through Inertia/JSON serialization — read them via the property directly in the controller.

### Cloudflare token stays on the panel
`CloudflareService` is a **panel-side** HTTP client. The Cloudflare API token never transits the gateway/agent — all CF calls (`verifyToken`, `listZones`, `createRecord`, `deleteRecord`) happen from Laravel. `DnsService` orchestrates `provisionDomain`/`teardownDomain` non-blocking: CF failures must never block app provisioning/deletion.

### SSL challenge: http vs dns
`enableSsl` takes a `challenge` param (`http` | `dns`, stored on `applications.ssl_challenge`). DNS-01 (`ssl_dns_provider = cloudflare`) writes a temporary `.cloudflare.ini`, runs certbot with `--dns-cloudflare`, then deletes the creds file. Falls back to HTTP-01 when no CF token is connected. The `certbot` provisioning step installs `python3-certbot-dns-cloudflare`.

### Server-alert notifications
`ThresholdChecker` fires `ServerAlertTriggered`/`ServerAlertResolved` (CPU/disk/memory ≥90%) **after** writing the alert row — never inline in the request path. The queued `SendAlertNotifications` listener fans out to every enabled `NotificationChannel` (email via Laravel mail, Slack via `SlackWebhookChannel`, Discord/Telegram via custom `Http::post`). Events are registered in `AppServiceProvider::boot()`.

### Backups
`BackupService` parses DB creds from the app's encrypted `env_content`, builds one bash script (`mysqldump`/`pg_dump` + `tar`, optional S3 upload with AWS creds injected as env vars), runs it as an agent job with a 1800s timeout. The agent prints `BACKUP_SIZE`; `GatewayInboundProcessor` parses it to update the `Backup` row. `BackupSetting` holds per-app schedule/retention; defaults live in the model `$attributes`. Restore is destructive (overwrites files + imports dump) and requires confirmation.

### Webhook auto-deploy hardening
Webhook routes are throttled (`throttle:60,1`). `DeploymentService::deploy()` has a concurrency guard: an overlapping deploy returns a `failed` deployment with a skip reason (prevents working-tree corruption); the webhook controller maps that to `{"status":"skipped_concurrent"}`. HMAC verification reads the raw request body.

### Deployment log viewer
`deployments/{deployment}/log` is a **flat** single-model binding (nested `apps/{app}/deployments/{deployment}` hits a Laravel two-model-binding quirk); the app is resolved via the deployment relation, and the controller exposes `application_uuid` for links. Output is rendered through `AnsiStripper` (handles SGR colour + non-SGR CSI sequences) and the page subscribes to the server's agent-job Reverb channel with a debounced `router.reload` for live streaming.

## Database

PostgreSQL is the primary store. SQLite is used in dev/testing (default `.env.example`). Migrations live in `panel/database/migrations/`.

## Model guidance (Sonnet vs Opus)

Per `docs/PLAN.md` §8 and `docs/TODO.md`:

- 🟢 **Sonnet is sufficient** for most UI work, CRUD controllers, migrations, frontend pages, config templates (Phases 0, 2, 4).
- 🔴 **Switch to Opus** for security-critical or architecturally complex tasks: Gateway Go internals, the agent transport layer, privileged execution logic, zero-downtime deploy engine, web terminal (PTY), and idempotent provisioning. **Remind the user to switch to Opus before starting any 🔴 task.**

### Route prefix for applications
Application detail routes use `/apps/{uuid}` (not `/applications/{uuid}`) — the `/applications/` prefix is blocked by Cloudflare WAF on the production panel. Route *names* still use the `applications.*` convention (e.g. `applications.show`, `applications.env`). The server-scoped listing routes (`servers/{server}/applications`, `servers/{server}/applications/create`) are unaffected and keep the `/applications` segment since they're under `/servers/`.

### Production nginx — critical note
The nginx `location /app/` block (with trailing slash) proxies Reverb WebSocket connections to port 8080. **It must use `location /app/` with a trailing slash.** Without the slash, `location /app` is a prefix match that also catches `/apps/` and `/applications/` routes, routing them to Reverb instead of PHP-FPM.

## Current status (as of 2026-06-29)

Phases 0–4 substantially complete. Shipped since 2026-06-16 (PRs #6, #14–#18):
- SSL: HTTP-01 + DNS-01 (Cloudflare) challenge
- Cloudflare DNS management (token CRUD, auto A-record on app create)
- Webhook auto-deploy endpoint, hardened (throttle + concurrency guard + `$hidden` secret)
- Notification system (email / Slack / Discord / Telegram) on server alerts
- Backup & restore (DB + files, local + S3, scheduling + retention)
- Deployment log viewer (ANSI-rendered, realtime via Reverb)
- Server security page: UFW firewall + fail2ban management
- 4 Dependabot security advisories resolved

Remaining work:
- Fase 3: Zero-downtime deploy (🔴 Opus), deploy key/OAuth integration
- Fase 5: Monitoring charts (metrics collection exists; alerts wired to notifications), web terminal (🔴 Opus)
- Cross-cutting: allowlist/job templates, mTLS, reinstall reminder for agents with old bigint server IDs
- Deferred: MongoDB provisioned without auth — panel-created Mongo users don't work yet

## Testing notes

Tests use Pest and are in `panel/tests/Feature/`. The test suite hits a SQLite in-memory database. Run `php artisan test` from `/panel`. Gateway and agent have Go unit tests; run `go test ./...` from `/gateway` and `/agent` respectively.
