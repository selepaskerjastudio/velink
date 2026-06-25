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
>   Fase 2 & Fase 4 yang banyak CRUD/template.
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
  - [x] nginx + certbot.
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
- [ ] SSL Let's Encrypt via certbot (opsional).
- [ ] Verifikasi: situs hidup di PHP 8.3, ganti ke 8.4, dan app legacy PHP 7.4.

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
- [ ] Endpoint webhook GitHub/GitLab → auto-deploy on push ke branch target.
- [x] Halaman riwayat deployment + log live (via Reverb, `agent_job_uuid` ↔
      `AgentJobUpdated`); tombol rollback masih menunggu mode ZDT.

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

- [ ] Agent: kumpulkan metrics (`gopsutil`: CPU/RAM/disk/load/net + status service).
- [ ] Kirim metrics periodik → simpan time-series (Postgres/Timescale atau rolling Redis).
- [ ] Dashboard: chart resource per server + multi-server overview.
- [ ] 🔴 **Opus** — Web terminal: agent buka PTY (`creack/pty`) ↔ Gateway ↔ xterm.js (multiplex channel).
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
    *(`applications/show.tsx` SSL card → `applications.ssl`.)*
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

- [ ] Audit log untuk semua aksi (siapa, server, perintah).
- [ ] Allowlist/Job bertemplate — hindari shell arbitrer dari UI.
- [ ] mTLS/token rotation untuk agent (opsional, tingkatkan keamanan).
- [ ] Dokumentasi instalasi panel + onboarding server.
- [ ] Uji end-to-end sesuai bagian Verifikasi di `PLAN.md`.
