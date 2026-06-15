# PLAN — Rencana Teknis & Arsitektur Velink

> Status: Draft v1. Tanggal: 2026-06-15. Lihat juga [`PRD.md`](./PRD.md) dan [`TODO.md`](./TODO.md).

## 1. Keputusan Arsitektur (sudah disepakati)

| Keputusan | Pilihan | Alasan singkat |
|---|---|---|
| Model komunikasi | **Agent dial-out** | Agent konek KELUAR ke panel; tembus NAT/firewall; tanpa simpan SSH key terpusat |
| Stack panel | **Laravel + Inertia + React** | Sesuai keahlian; sama seperti RunCloud; queue/scheduler/realtime built-in |
| Bahasa agent | **Go** | Single static binary, ringan, ideal untuk daemon/metrics/PTY |
| Prioritas MVP | App PHP + versi PHP per-app, Deploy Git, Service/worker/cron | Monitoring + web terminal menyusul |

> Catatan: saran greenfield environment yang mengarah ke Next.js/Vercel **tidak dipakai** —
> ini tool infrastruktur yang mengelola systemd/php-fpm/supervisord langsung di VM.

## 2. Arsitektur Sistem

Tiga komponen utama + Redis + DB:

```
┌──────────────────────── Control Panel VM ────────────────────────┐
│  Laravel (Inertia + React)  — UI, logika bisnis, DB, orkestrasi   │
│  Horizon (queue) · Reverb (websocket browser)                     │
│  Gateway (Go)   — terminasi koneksi WSS agent, jembatan via Redis │
│  PostgreSQL · Redis (queue/cache/pubsub/presence)                 │
└───────────────────────────────┬───────────────────────────────────┘
                                 │  WSS keluar (agent dial-out)
        ┌────────────────────────┼────────────────────────┐
        ▼                        ▼                        ▼
  ┌─ Managed VM ─┐        ┌─ Managed VM ─┐         ┌─ Managed VM ─┐
  │ Agent (Go)   │        │ Agent (Go)   │         │ Agent (Go)   │
  │ nginx        │        │ php 7.4/8.x  │         │ supervisord  │
  │ php-fpm xN   │        │ mysql/pg/    │         │ cron/systemd │
  │              │        │ mongo/redis  │         │              │
  └──────────────┘        └──────────────┘         └──────────────┘
```

### Pembagian tanggung jawab
- **Panel (Laravel)** = otak: UI, DB, validasi, membuat "Job", merender template config
  (nginx/fpm/supervisor), audit log, OAuth Git, webhook.
- **Gateway (Go)** = transport realtime: memegang koneksi WebSocket persisten dari semua
  agent, mem-bridge ke Laravel lewat Redis pub/sub. Kecil, satu binary, jalan di VM panel.
  (Dipisah dari Laravel karena PHP-FPM tidak cocok memegang socket persisten.)
- **Agent (Go)** = tangan: satu binary di tiap server, dial-out WSS ke gateway,
  mengeksekusi Job (provisioning, deploy, kontrol service), kirim heartbeat/metrics,
  sediakan PTY untuk web terminal. Jalan sebagai systemd service dengan hak privileged.

### Protokol Agent ↔ Gateway (model Job)
1. Tambah server di panel → generate token unik + perintah install one-liner.
2. Agent dial-out `wss://panel/agent/connect` dengan token (verifikasi TLS cert panel).
3. Panel membuat `Job` → publish ke Redis → Gateway push ke agent yang tepat.
4. Agent eksekusi → stream stdout/stderr/exit-code → Gateway → Redis → Laravel update
   status + broadcast progress ke browser via Reverb.
5. Heartbeat + presence di Redis (status online/offline server).

> MVP bisa mulai tanpa Gateway (agent HTTP long-poll), tapi karena web terminal &
> live-metrics tetap dibutuhkan, kita bangun **Gateway Go dari awal** agar tidak rework.
> Abstraksi `Job` tetap sama di kedua mode.

## 3. Tech Stack

| Lapisan | Pilihan | Catatan |
|---|---|---|
| Panel backend | Laravel 12, PHP 8.4 | Horizon, scheduler, Reverb built-in |
| Panel frontend | Inertia 2 + React 19 + Vite + Tailwind + shadcn/ui | SPA-feel tanpa API terpisah |
| Auth | Laravel Fortify + 2FA (TOTP) | Panel privileged wajib 2FA |
| Queue | Laravel Horizon (Redis) | Job provisioning/deploy async |
| Realtime browser | Laravel Reverb | Progress deploy, status, terminal ke browser |
| Panel DB | PostgreSQL 16 | Relasional + JSONB untuk config |
| Gateway & Agent | Go 1.23+ | `coder/websocket`, `creack/pty` (PTY), `gopsutil` (metrics) |
| Managed OS | Ubuntu 22.04/24.04 LTS | `ondrej/php` PPA (7.4 + 8.x), PGDG (Postgres), repo resmi MongoDB |

### Struktur repo (monorepo)
```
/panel       # Laravel app (Inertia + React)
/gateway     # Go realtime gateway
/agent       # Go agent (binary terdistribusi)
/provision   # template config (nginx/fpm/supervisor) + script provisioning
/installer   # script install agent (curl | bash)
/docs        # PRD, PLAN, TODO
```

## 4. Skema Data Inti (Laravel migrations)

- `users`, `audit_logs`
- `servers` — host, token (hashed), status, os, resource snapshot, public_ip
- `git_providers` / `git_credentials` — OAuth GitHub/GitLab, token (encrypted cast)
- `applications` — server_id, domain, root_path, linux_user, php_version, repo, branch,
  deploy_mode (inplace/zerodowntime), deploy_script
- `php_pools` — application_id, php_version, fpm socket/conf
- `deployments` — application_id, commit, status, log, started/finished
- `services` — server_id, type (systemd/supervisor), name, status, config
- `cron_jobs` — server_id, schedule, command, user
- `databases` / `database_users` — server_id, engine (mysql/mariadb/postgres/mongodb),
  name, grants

Rahasia (token Git, password DB, isi `.env`) disimpan pakai **encrypted cast** Laravel.

> **Identifier eksternal (UUID):** `servers`, `applications`, `git_credentials`,
> `deployments` (dan `agent_jobs` sejak Fase 1) punya kolom `uuid` terpisah sebagai
> route-key (trait `HasUuidRouteKey`: generate `Str::uuid()` saat `creating`,
> `getRouteKeyName() => 'uuid'`). Bigint `id` tetap dipakai untuk relasi/FK internal
> (join, `where('application_id', ...)`, dll) — hanya representasi yang terekspos ke
> URL/payload Inertia/channel broadcast dan protokol agent↔gateway (`server_id`,
> `X-Server-Id`, `--server-id`) yang berubah ke uuid.

## 5. Roadmap Berfase

### Fase 0 — Fondasi
- Scaffold `/panel`: Laravel + Fortify (Inertia+React), 2FA, Tailwind+shadcn.
- Setup Horizon, Reverb, PostgreSQL, Redis. Migrasi skema inti.
- Skeleton `/gateway` (Go): endpoint `wss://.../agent/connect`, auth token, presence di
  Redis, bridge pub/sub ke Laravel.
- Flow enrollment server: halaman "Add Server" → token + perintah install.

### Fase 1 — Agent + eksekusi command (inti MVP)
- `/agent` (Go): enroll, dial-out WSS, heartbeat, **executor Job** (jalankan shell, tulis
  file, render config dari payload), stream output. Jalan sebagai systemd unit, privileged.
- Abstraksi `Job` di Laravel (model + state machine + broadcast progress via Reverb).
- **Provisioning server (agent yang install semua service):**
  - nginx, php-fpm **7.4 + 8.1/8.2/8.3/8.4** (PPA `ondrej/php`), composer, node,
    supervisord, certbot.
  - Database: MySQL/MariaDB, **PostgreSQL** (PGDG), **MongoDB** (repo resmi) — dipilih per server.
  - Redis.
  - Semua sebagai Job idempotent. Satu-satunya yang manual di target = agent itu sendiri.

### Fase 2 — Manajemen App + versi PHP per-app
- Buat aplikasi: render vhost nginx + php-fpm pool (template) terikat versi PHP terpilih;
  buat **Linux user terisolasi per-site** (pool user sendiri, `open_basedir`).
- **Ganti versi PHP:** regenerate fpm pool + upstream nginx → reload (downtime minimal).
- SSL Let's Encrypt via certbot (opsional MVP+).

### Fase 3 — Deploy Git (GitHub & GitLab)
- OAuth GitHub + GitLab; simpan token (encrypted); list repo; pasang deploy key.
- **Dua mode deploy per aplikasi** (`deploy_mode`):
  - **Update biasa (in-place):** deploy script editable (default `git pull` →
    `composer install --no-dev` → `npm ci && build` → `migrate --force` → restart worker
    → reload fpm). Cepat, sedikit downtime.
  - **Zero-downtime:** `releases/<ts>` + symlink `current` atomik + `shared/` (.env,
    storage) + simpan N rilis untuk rollback. (Boleh shell-out ke **Deployer**.)
- Editor deploy script dari dashboard untuk kedua mode.
- Webhook GitHub/GitLab → auto-deploy saat push ke branch target.
- Editor `.env` dari dashboard (encrypted).

### Fase 4 — Service / Worker / Cron
- systemd: start/stop/restart/status via agent.
- Supervisord: generate program conf untuk queue worker (`queue:work`/Horizon),
  restart, baca status/log.
- Cron: crontab terkelola (drop-in `/etc/cron.d/`) dari UI.

### Fase 5 — Monitoring + Web Terminal (fase lanjutan)
- Agent kumpulkan metrics (`gopsutil`) → kirim periodik → time-series (Postgres/Timescale
  atau rolling Redis) → chart dashboard.
- Web terminal: agent buka PTY (`creack/pty`) ↔ Gateway ↔ **xterm.js** di browser
  (stream byte 2 arah, multiplex channel di socket yang sama).

## 6. Keamanan (wajib — agent privileged)

- Auth agent: token per-server (hashed di DB), opsi mTLS; agent verifikasi cert panel.
- **Jangan eksekusi shell arbitrer dari UI** — Job bertemplate + allowlist; web terminal
  adalah jalur eksplisit/teraudit terpisah.
- Audit log semua aksi (siapa, server mana, perintah apa).
- Identifier eksternal (URL, payload Inertia, channel broadcast, protokol
  agent↔gateway) memakai UUID per-model (kolom `uuid` + `HasUuidRouteKey`), bukan
  bigint sekuensial — mencegah enumerasi resource antar user.
- Enkripsi at-rest untuk token Git, password DB, `.env` (encrypted cast).
- Isolasi per-site: fpm pool user terpisah, `open_basedir`, permission ketat.
- Panel di balik VPN/firewall, TLS wajib, 2FA untuk semua user.

## 7. Verifikasi (end-to-end)

1. **Panel lokal:** `php artisan serve` + `npm run dev` + `php artisan horizon` +
   `php artisan reverb:start`; login + 2FA berfungsi.
2. **Server uji:** VM Ubuntu lokal via **multipass** (`multipass launch 24.04`). "Add
   Server" → jalankan one-liner installer → agent **online** (presence di Redis),
   heartbeat jalan.
3. **Provisioning:** trigger provision → verifikasi nginx, php 7.4 + 8.1–8.4,
   mysql/mariadb, postgres, mongodb, redis, supervisord terpasang (Job log + `systemctl status`).
4. **App + versi PHP:** buat app PHP 8.3 → cek vhost + fpm pool, situs merespons; ganti ke
   8.4 → `phpinfo()` berubah; uji juga app legacy **PHP 7.4**.
5. **Deploy Git:** hubungkan repo Laravel GitHub → deploy (uji kedua mode) → cek hasil;
   push commit → webhook memicu auto-deploy.
6. **Service/worker/cron:** buat worker supervisord (`queue:work`) → restart dari UI → cek
   proses; tambah cron → cek `/etc/cron.d/`.
7. **Audit:** konfirmasi semua aksi tercatat di `audit_logs`.

## 8. Panduan Model (Sonnet vs Opus)

Untuk efisiensi biaya/kecepatan tanpa mengorbankan kualitas di bagian kritis:

- 🟢 **Sonnet cukup** — kerja mekanis berpola standar: mayoritas **Fase 0** (scaffold,
  auth, migrasi, CRUD, UI), **Fase 2** & **Fase 4** (banyak CRUD/template config).
- 🔴 **Pindah ke Opus** (`/model` → Opus) — bagian rawan desain/keamanan:
  **Skeleton Gateway Go** (akhir Fase 0), **seluruh Fase 1** (transport agent, eksekusi
  privileged, provisioning idempotent), **mesin deploy zero-downtime + rollback** (Fase 3),
  dan **web terminal** (Fase 5).

Penanda 🟢/🔴 per task ada di [`TODO.md`](./TODO.md). Claude akan mengingatkan saat
mencapai task 🔴 sebelum mengerjakannya.

## 9. Risiko & Catatan

- **Paling berisiko:** (a) transport agent (reconnect, auth, multiplex stream),
  (b) eksekusi privileged yang aman, (c) provisioning multi-PHP/DB idempotent.
  Bangun & uji ketiganya lebih dulu.
- Mulai dukung **Ubuntu LTS saja**; distro lain belakangan.
- Pakai **Deployer** untuk mesin deploy zero-downtime agar tidak menulis ulang.
- Skala puluhan VM → satu Gateway Go di VM panel cukup; tidak perlu cluster.
