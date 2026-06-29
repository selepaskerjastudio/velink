# AGENTS.md

Guide for AI coding agents working in this repo. Tool-agnostic, canonical agent doc. For full architecture detail see [CLAUDE.md](CLAUDE.md) and `docs/` (PRD.md, PLAN.md, TODO.md).

> `CLAUDE.md` is the detailed companion read by Claude Code. Keep this file and CLAUDE.md in sync when either changes.

## Project

**Velink** — self-hosted server control panel (RunCloud/Forge-style) for managing multiple Ubuntu VMs: PHP apps (per-app PHP version), databases, Redis, systemd services, supervisord workers, cron, Git deploys, SSL, DNS, backups, firewall — no manual SSH.

## Layout (monorepo)

| Dir | What |
|---|---|
| `/panel` | Laravel 12 + Inertia 2 + React 19 — UI + backend ("brain") |
| `/gateway` | Go WebSocket bridge (panel ↔ agents), stateless, runs on panel VM |
| `/agent` | Go static binary on each managed VM (dials out to gateway) |
| `/provision` | nginx/php-fpm/supervisor templates + provisioning scripts |
| `/installer` | curl\|bash agent installer |
| `/docs` | PRD.md, PLAN.md, TODO.md |

Data flow: `Browser ↔ Reverb(WS) ↔ Laravel ↔ Redis pub/sub ↔ Gateway(Go) ↔ Agent(Go)`.

## Commands (run from `/panel` unless noted)

```bash
composer run dev        # serve + queue:listen + pail + vite, all at once
php artisan test                       # all Pest tests
php artisan test --filter TestName     # one test/class
php artisan test tests/Feature/X.php   # one file
npm run build           # prod vite build
npm run lint            # ESLint --fix
npm run format          # Prettier
```

Go (from `/gateway` or `/agent`):
```bash
go build ./...   &&   go vet ./...   &&   go test ./...
```

Tests: Pest, `panel/tests/Feature/`, SQLite in-memory. Always run `php artisan test` (and `go test ./...` if you touched Go) before claiming done. Most features here ship TDD — add tests with the change.

## Hard rules (do not violate)

1. **UUID route keys.** Models in URLs / inter-service protocols use the `uuid` column via `HasUuidRouteKey`. Bigint `id` is internal FK only. Never expose raw `id` in URLs, Inertia props, broadcast channels, or agent↔gateway headers.
2. **Protocol stays in sync.** `App\Support\GatewayProtocol` (PHP) and `gateway/internal/protocol/protocol.go` (Go) — channel names, message types, header names must match. Change both.
3. **Encrypted secrets.** Git tokens, DB passwords, `.env` contents, Cloudflare tokens, notification webhook URLs/bot tokens, webhook secrets → Laravel `encrypted` cast, never plaintext. Add secret columns to `$hidden` so they don't leak via serialization; read them via the property in the controller.
4. **Cloudflare token never leaves the panel.** `CloudflareService` is a panel-side HTTP client; the CF token does not transit gateway/agent. DNS ops (`DnsService`) are non-blocking — CF failure must not block provisioning/deletion.
5. **App routes use `/apps/{uuid}`**, not `/applications/{uuid}` (blocked by Cloudflare WAF in prod). Route *names* keep `applications.*`. Server-scoped listing (`servers/{server}/applications`) is fine.
6. **nginx `location /app/` needs the trailing slash** (proxies Reverb WS). Without it, `/app` prefix-matches `/apps/` and `/applications/` and breaks PHP-FPM routing.
7. **Config templates** are Go `text/template` in `App\Provisioning\AppTemplates`, rendered by the agent via `render_config` jobs from a flat `snake_case` map. php-fpm socket keyed by `linux_user` (not `php_version`).
8. **Don't fire notifications/alerts inline** in the request path — write the row, then emit an event handled by a queued listener (`ThresholdChecker` → `ServerAlert*` events → `SendAlertNotifications`).
9. **Deploy concurrency guard:** `DeploymentService::deploy()` returns a `failed` deployment (skip reason) on overlap — don't remove it; it prevents working-tree corruption.

## Model choice (Sonnet vs Opus)

- 🟢 **Sonnet:** UI, CRUD controllers, migrations, frontend pages, config templates.
- 🔴 **Opus (remind the user to switch first):** Gateway Go internals, agent transport, privileged execution, zero-downtime deploy engine, web terminal (PTY), idempotent provisioning.

## Status (2026-06-29)

Phases 0–4 substantially done. Shipped recently: HTTP-01 + DNS-01 SSL, Cloudflare DNS, hardened webhook auto-deploy, notifications (email/Slack/Discord/Telegram), backup & restore (local + S3), realtime deploy-log viewer, UFW + fail2ban security page.

Remaining: zero-downtime deploy (🔴), deploy key/OAuth, monitoring charts, web terminal (🔴), allowlist/job templates, mTLS. Deferred: MongoDB provisioned without auth (panel Mongo users don't work yet).
