# TODO — Velink

> Checklist pengerjaan. Lihat [`PRD.md`](./PRD.md) dan [`PLAN.md`](./PLAN.md).
> Urutan: kerjakan **3 bagian berisiko** dulu (transport agent, eksekusi privileged
> aman, provisioning multi-PHP/DB idempotent) sebelum menumpuk fitur UI.

## 🧠 Panduan Model (Sonnet vs Opus)

> **Reminder pindah model.** Item bertanda **🟢 Sonnet** = scaffolding mekanis,
> cukup pakai Sonnet (lebih cepat & murah). Item bertanda **🔴 Opus** = desain/keamanan
> rawan salah, sebaiknya pindah ke Opus (`/model` → Opus).
>
> - 🟢 **Sonnet cukup:** mayoritas Fase 0 (scaffold, auth, migrasi, CRUD, UI),
>   Fase 2 & 4 yang banyak CRUD/template.
> - 🔴 **Pindah ke Opus:** Skeleton Gateway Go (akhir Fase 0), **seluruh Fase 1**
>   (transport agent, eksekusi privileged, provisioning idempotent), mesin deploy
>   Fase 3 (zero-downtime + rollback), dan web terminal Fase 5.
>
> Claude akan **mengingatkan** saat sampai di task 🔴 sebelum mulai mengerjakannya.

## Fase 0 — Fondasi 🟢 Sonnet

- [x] Inisialisasi monorepo: folder `/panel`, `/gateway`, `/agent`, `/provision`, `/installer`, `/docs`.
- [x] Scaffold `/panel` Laravel 12 + Inertia 2 + React 19 + Vite + Tailwind + shadcn/ui.
- [x] Setup auth Fortify + 2FA (TOTP). _(catatan: react-starter-kit v1.0.1 tidak bundle Fortify
      karena PHP lokal 8.2 → 2FA diimplementasikan custom dengan `pragmarx/google2fa` +
      `bacon/bacon-qr-code`, terintegrasi ke alur login/2FA custom starter kit)._
- [x] Setup PostgreSQL, Redis, Horizon, Reverb (env + service).
- [x] Migrasi skema inti: `users`, `audit_logs`, `servers`, `git_providers`,
      `git_credentials`, `applications`, `php_pools`, `deployments`, `services`,
      `cron_jobs`, `databases`, `database_users`.
- [x] Encrypted cast untuk field rahasia (token Git, password DB, `.env`).
- [x] 🔴 **Opus** — Skeleton `/gateway` (Go): endpoint `wss://.../agent/connect`,
      auth token, presence di Redis, bridge pub/sub ↔ Laravel. _(build/vet/test bersih;
      verifikasi token via panel `POST /internal/agent/verify` + shared secret
      `GATEWAY_SECRET`, karena `agent_token` di-hash di DB)._
- [x] Halaman "Add Server": generate token + tampilkan perintah install one-liner.

## Fase 1 — Agent + Eksekusi Command (inti MVP) ⚠️ risiko tinggi · 🔴 Opus

- [x] `/agent` (Go): bootstrap, baca config/token, dial-out WSS ke gateway.
- [x] Agent: heartbeat + reconnect otomatis (backoff) + verifikasi TLS cert panel
      (wss default; `AGENT_INSECURE=1` hanya untuk dev).
- [x] Agent: **executor Job** (jalankan shell, tulis file, render config dari payload),
      stream stdout/stderr/exit-code. _(unit test executor lulus)._
- [x] Agent: jalan sebagai systemd unit dengan hak privileged (unit di `/installer`).
- [x] Panel: model `AgentJob` + state machine + dispatch (Redis) + listener
      `agent:listen` + broadcast `AgentJobUpdated`/`ServerPresenceUpdated` via Reverb.
- [x] `/installer`: script `curl | bash` (`agent.sh`) untuk pasang agent + daftarkan ke panel.
- [x] **Provisioning (Job idempotent, agent yang install)** — katalog `ProvisioningCatalog`
      + `ProvisionService` (base dulu, dispatch sebagai job `shell`):
  - [x] nginx + certbot (+ `certbot-dns-cloudflare` untuk DNS-01 SSL).
  - [x] php-fpm **7.4, 8.1, 8.2, 8.3, 8.4** via PPA `ondrej/php` + composer + node.
  - [x] supervisord.
  - [x] MySQL/MariaDB.
  - [x] PostgreSQL (repo PGDG).
  - [x] MongoDB (repo resmi MongoDB).
  - [x] Redis.
- [x] UI: pilih service yang akan diinstall per server + lihat progress provisioning
      (live via Reverb).

## Fase 2 — App PHP + Versi PHP per-App 🟢 Sonnet

- [x] Template config: vhost nginx + php-fpm pool (per versi PHP).
- [x] Buat aplikasi: domain, root_path, **Linux user terisolasi per-site** (`open_basedir`).
- [x] Pilih versi PHP per app (7.4–8.4) saat buat app.
- [x] **Ganti versi PHP:** regenerate fpm pool + upstream nginx → reload (downtime minimal).
- [x] Editor `.env` aplikasi (encrypted).
- [x] SSL Let's Encrypt via certbot — **HTTP-01 dan DNS-01 (Cloudflare)**.
      *(HTTP-01: `certbot --nginx`. DNS-01: `certbot certonly --dns-cloudflare` —
      menulis `/root/.cloudflare.ini` temporer, hapus setelah selesai. PR #15.)*
- [x] Verifikasi: situs hidup di PHP 8.3, ganti ke 8.4, dan app legacy PHP 7.4.
      *(Diverifikasi di server produksi.)*

## Fase 3 — Deploy Git (GitHub & GitLab) 🟢 Sonnet (mesin deploy ZDT 🔴 Opus)

- [x] Kredensial Git via Personal Access Token (manual, encrypted) untuk
      GitHub & GitLab — halaman "Git credentials". _(OAuth app penuh
      ditunda; PAT manual cukup untuk clone/fetch via agent.)_
- [ ] List repo + pasang deploy key (butuh OAuth app — ditunda).
- [x] **Mode "Update biasa" (in-place):** deploy script editable (default git
      fetch+reset → `composer install --no-dev` → `npm ci && build` →
      `migrate --force` + cache artisan), dijalankan sebagai `linux_user`
      aplikasi via `sudo -u`.
- [ ] 🔴 **Opus** — **Mode "Zero-downtime":** `releases/<ts>` + symlink `current` atomik
      + `shared/` (.env, storage) + rollback (boleh shell-out ke Deployer).
- [x] Pilihan `deploy_mode` per aplikasi + editor deploy script di dashboard
      (Select `deploy_mode` saat ini hanya mengizinkan `inplace`; opsi
      zero-downtime tampil disabled "coming soon").
- [x] Endpoint webhook GitHub/GitLab → auto-deploy on push ke branch target.
      *(PR #18 — `WebhookController` dengan HMAC signature verification,
      concurrency guard, throttle middleware, dan branch matching.)*
- [x] Halaman riwayat deployment + log live (via Reverb, `agent_job_uuid` ↔
      `AgentJobUpdated`); tombol rollback masih menunggu mode ZDT.
      *(PR #6 — Deployment Log Viewer dengan ANSI color + realtime via Reverb.)*

## Fase 4 — Service / Worker / Cron 🟢 Sonnet

- [x] systemd: start/stop/restart/status via agent + UI (`services.index/store/control/refresh-status/destroy`,
      `ServiceManager`, `servers/services.tsx`).
- [x] Supervisord: generate program conf untuk queue worker (`queue:work`/Horizon),
      restart, baca status/log dari UI (`workers.index/store/update/control/destroy`,
      `WorkerService`, `WorkerTemplates`, `applications/workers.tsx`).
- [x] Cron: crontab terkelola (drop-in `/etc/cron.d/velink`) dari UI
      (`cron.index/store/update/toggle/destroy`, `CronService`, `CronTemplates`,
      `servers/cron.tsx`).
- [x] Manajemen database dari UI: buat/hapus DB (`databases.*`, `DatabaseProvisionService`,
      `servers/databases.tsx`) + user + atur grants MySQL/PG/Mongo
      (`database-users.*`, `DatabaseUserProvisionService`, `servers/database-users.tsx`,
      one-time password flash).

## Fase 5 — Monitoring + Web Terminal (lanjutan) 🟢 Sonnet (web terminal 🔴 Opus)

- [x] Agent: kumpulkan metrics (`gopsutil`: CPU/RAM/disk/load/net + status service).
      *(Agent `metrics/collector.go` — Snapshot dikirim setiap 30 detik.)*
- [x] Kirim metrics periodik → simpan time-series (Postgres/Timescale atau rolling Redis).
      *(PostgreSQL, retention 7 hari, `server_metrics` table.)*
- [x] Dashboard: chart resource per server + multi-server overview.
      *(Monitoring page dengan LineChart CPU/RAM/Load/Disk, donut gauges, time range selector.)*
- [x] 🔴 **Opus** — Web terminal: agent buka PTY (`creack/pty`) ↔ Gateway ↔ xterm.js (multiplex channel).
      *(PR #19 — Direct gateway WebSocket. Browser ↔ gateway `/terminal/connect` ↔ agent PTY.
      User-selectable shell user (root/velink/custom). `runuser` untuk switch user tanpa password.)*
- [ ] Audit khusus sesi terminal.

## Fase 6 — Parity RunCloud (UI/UX + Monitoring) 🟢 Sonnet

> Berdasarkan analisis screenshot RunCloud. Detail lihat [`RUNCLOUD_ANALYSIS.md`](./RUNCLOUD_ANALYSIS.md).

### 🔴 Tinggi

- [x] **Uptime di dashboard** — Agent kirim `uptime_seconds` di metrics payload →
      kolom baru di `server_metrics` → card dashboard ganti CPU → Uptime (format: "3d 12h 4m").
      CPU tetap ditampilkan di chart monitoring.
      *(Migration `2026_06_16_000001`, `ServerMetric` model cast, `show.tsx` Uptime card, `monitoring.tsx` range query — all wired end-to-end.)*
- [x] **Halaman Monitoring dedicated** (`servers/monitoring.tsx`) — route
      `GET servers/{server}/monitoring`, nav sidebar "Monitoring" ter-link,
      chart CPU/RAM/Load/Disk terpisah, donut gauge Memory & Disk (`HalfDonutGauge`),
      time range selector (1h / 6h / 24h / 7d).
      *(`ServerController::monitoring`, route `servers.monitoring`, sidebar entry active-state benar.)*
- [x] **Settings page: edit server name** — form edit `name` → `PATCH /servers/{server}`.
      *(`ServerController::settings` + `update`, `servers/settings.tsx`.)*
- [x] **Settings page: restart server** — dispatch `AgentJob shell` dengan `sudo reboot`.
      *(`ServerController::restart`, restart dialog di settings page.)*

### 🟡 Medium

- [x] **Web Applications list page per server** (`servers/applications.tsx`) —
      route `GET servers/{server}/applications`, `ApplicationController@serverIndex`,
      tabel dengan search + kolom Owner (linux_user).
      *(Nav sidebar "Web Applications" sekarang ter-link ke route yang benar — bukan duplikat dashboard.)*
- [x] **Databases: gabungkan DB + DB Users satu halaman** — tabs per-engine
      "Databases" / "Users" di `servers/databases.tsx`, route `database-users.index`
      redirect ke `databases.index`.
      *(`DatabaseInstanceController::index` kirim `databases` + `databaseUsers`; `DatabaseUserController::index` redirect.)*
- [x] **Databases: tambah kolom** `created_at` ("Added On") dan `collation` di tabel.
      *(`DatabaseInstanceSummary` type + `DatabasesPanel` table columns.)*

### 🟢 Rendah

- [x] Search/filter di halaman databases, cron jobs, workers.
    *(Databases & cron jobs sudah punya search; **workers** search + directory info ditambahkan 2026-06-23.)*
- [x] Workers: tambah kolom "Directory" (root_path aplikasi).
    *(2026-06-23 — `workers.tsx` menampilkan root_path di deskripsi card.)*
- [x] App detail: tampilkan "Directory Size".
    *(2026-06-23 — kolom `directory_size_bytes`, endpoint `applications.directory-size`, inbound processor parse output `du -sb`, Summary card + tombol Refresh.)*
- [x] Services: per-service CPU% dan Memory usage.
    *(2026-06-23 — `handleMetrics` roll-up dari field `services[]` ke tabel `services`; agent `metrics/services.go` koleksi via cgroup + VmRSS.)*
- [x] App detail: SSL/TLS UI (trigger certbot yang sudah ada di provisioning).
    *(`applications/show.tsx` SSL card → `applications.ssl`. HTTP-01 + DNS-01 (Cloudflare) PR #15.)*
- [x] App detail: NGINX Config editor.
    *(2026-06-23 — `ApplicationController::nginxConfig` + test `NginxConfigControllerTest`, `applications/show.tsx` section "NGINX Config".)*

## Lintas-Fase (Keamanan & Kualitas)

- [x] **Identifier eksternal → UUID (2026-06-15) — 🟢 Sonnet.** `servers`,
      `applications`, `git_credentials`, `deployments`, **`databases`**, **`database_users`**
      kini punya kolom `uuid` terpisah sebagai route-key (trait `HasUuidRouteKey`,
      sama seperti `agent_jobs` di Fase 1) — URL, payload Inertia (`id`,
      `server_id`→dihapus, `git_credential_id`), channel broadcast (`server.{uuid}`),
      dan protokol agent↔gateway (`server_id`/`X-Server-Id`/`--server-id`) memakai
      uuid; bigint `id` tetap untuk relasi/FK internal. Migration
      `2026_06_16_000003` menambah kolom uuid ke `databases` dan `database_users`.

- [x] **Route `/apps/` prefix (2026-06-16).** Route detail aplikasi diganti dari
      `/applications/{uuid}` ke `/apps/{uuid}` agar tidak diblokir Cloudflare WAF.
      Route name (`applications.show`, dll) tidak berubah. Nginx `location /app/`
      (trailing slash wajib) memisahkan path Reverb WebSocket dari route `/apps/`.

- [ ] **Catatan rilis (bigint→UUID server identifier):** setelah perubahan ini
      dirilis, agent yang sudah terpasang punya `AGENT_SERVER_ID=<bigint>` lama
      di `/etc/velink/agent.env` — harus dijalankan ulang `installer/agent.sh`
      dengan `--server-id=<uuid>` baru dari panel agar `X-Server-Id`/`server_id`
      cocok dengan validasi UUID di gateway & panel.

- [x] Audit log untuk semua aksi (siapa, server, perintah).
      *(`AuditLogger::log()` dipanggil di setiap controller mutating action —
      create/delete/deploy/SSL/firewall/backup/notification/dns/terminal.)*
- [ ] Allowlist/Job bertemplate — hindari shell arbitrer dari UI.
- [ ] mTLS/token rotation untuk agent (opsional, tingkatkan keamanan).
- [x] Dokumentasi instalasi panel + onboarding server.
      *(`README.md` lengkap: setup panel, gateway, agent installer, deployment, development.)*
- [ ] Uji end-to-end sesuai bagian Verifikasi di `PLAN.md`.

---

## Fitur Tambahan (di luar roadmap awal)

> Fitur-fitur berikut ditambahkan di luar Fase 0–6 dan sudah selesai.

- [x] **SSH Key Management (2026-06-24)**
      Global per-user SSH keys, deploy ke managed server via SystemUser.
      *(PR #8 — `SshKey` model, fingerprint (pure PHP), `SshKeyService`,
      deploy/revoke per SystemUser. `settings/ssh-keys.tsx` + `servers/ssh-keys.tsx`.)*

- [x] **System User Management (2026-06-24)**
      OS user CRUD per server: add/delete, sudo toggle, shell selection.
      SSH keys dapat dideploy ke user mana saja.
      *(PR #9 — `SystemUser` model, `SystemUserProvisionService`, `SystemUserController`.
      SSH key feature di-refactor agar user-aware.)*

- [x] **Velink webapp user SSH access — RunCloud model (2026-06-25)**
      User `velink` jadi SSH-accessible (shell bash, bukan nologin).
      `ensureWebappUser()` auto-register + `chsh` saat server punya apps.
      *(PR #11 + #12 — `AppProvisionService` shell fix + ownership fix.)*

- [x] **Security: Firewall (UFW) + Fail2Ban (2026-06-25)**
      UFW: port rules, default SSH/HTTP/HTTPS, DB source of truth sync.
      Fail2Ban: install via provisioning, manual ban/unban via `fail2ban-client`.
      *(PR #13 — `FirewallRule` model, `FirewallService`, `Fail2BanService`,
      `SecurityController`, `servers/security.tsx`.)*

- [x] **Deployment Log Viewer with ANSI Color + Realtime (2026-06-25)**
      Dedicated full-page log viewer with terminal-style ANSI rendering,
      realtime streaming via Reverb, download, prev/next navigation.
      *(PR #6 — `DeploymentLogController`, `AnsiStripper`, `deployments/show.tsx`.)*

- [x] **Cloudflare DNS Management + DNS-01 SSL (2026-06-26)**
      Connect Cloudflare account, auto-create A records on app creation,
      DNS-01 SSL challenge via certbot-dns-cloudflare (no HTTP needed).
      *(PR #15 — `CloudflareToken` model, `CloudflareService` (panel-side HTTP),
      `DnsService`, `DnsRecord` model, `DnsRecordController`, `apps/dns.tsx`.)*

- [x] **Notification System (2026-06-26)**
      Server alert notifications via Email + Slack + Discord + Telegram.
      Events fire on threshold trigger/resolve, queued listener delivers.
      *(PR #16 — `NotificationChannel` model, `ServerAlertNotification`,
      `SendAlertNotifications` listener, `DiscordWebhookService`, `TelegramService`.)*

- [x] **Backup & Restore (2026-06-26)**
      Per-app backup: database dump + app files, local + S3 storage,
      optional schedule (off/daily/weekly/monthly), retention auto-prune.
      *(PR #17 — `Backup` model, `BackupSetting` model, `BackupService`,
      `BackupController`, `apps/backups.tsx`.)*

- [x] **Webhook Auto-Deploy Hardening (2026-06-29)**
      Concurrency guard (reject overlapping deploys), throttle middleware,
      webhook_secret hidden from Inertia, improved test coverage.
      *(PR #18 — `DeploymentService` guard, `WebhookController` skip response.)*

- [x] **Web Terminal (2026-06-29)**
      Direct gateway WebSocket + PTY via creack/pty. User-selectable shell.
      Browser ↔ gateway `/terminal/connect` ↔ agent PTY in real-time.
      *(PR #19 — `terminal` package (agent), `relay` package (gateway),
      `TerminalController` (panel), xterm.js frontend.)*

- [x] **Security Advisories Resolved (2026-06-26)**
      4 Dependabot alerts fixed: guzzlehttp/guzzle, guzzlehttp/psr7, shell-quote.
      *(PR #14 — 0 vulnerabilities remaining.)*
