# TODO — coruncloud

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

- [ ] `/agent` (Go): bootstrap, baca config/token, dial-out WSS ke gateway.
- [ ] Agent: heartbeat + reconnect otomatis + verifikasi TLS cert panel.
- [ ] Agent: **executor Job** (jalankan shell, tulis file, render config dari payload),
      stream stdout/stderr/exit-code.
- [ ] Agent: jalan sebagai systemd unit dengan hak privileged.
- [ ] Panel: model `Job` + state machine + broadcast progress via Reverb.
- [ ] `/installer`: script `curl | bash` untuk pasang agent + daftarkan ke panel.
- [ ] **Provisioning (Job idempotent, agent yang install):**
  - [ ] nginx + certbot.
  - [ ] php-fpm **7.4, 8.1, 8.2, 8.3, 8.4** via PPA `ondrej/php` + composer + node.
  - [ ] supervisord.
  - [ ] MySQL/MariaDB.
  - [ ] PostgreSQL (repo PGDG).
  - [ ] MongoDB (repo resmi MongoDB).
  - [ ] Redis.
- [ ] UI: pilih service yang akan diinstall per server + lihat progress provisioning.

## Fase 2 — App PHP + Versi PHP per-App 🟢 Sonnet

- [ ] Template config: vhost nginx + php-fpm pool (per versi PHP).
- [ ] Buat aplikasi: domain, root_path, **Linux user terisolasi per-site** (`open_basedir`).
- [ ] Pilih versi PHP per app (7.4–8.4) saat buat app.
- [ ] **Ganti versi PHP:** regenerate fpm pool + upstream nginx → reload (downtime minimal).
- [ ] Editor `.env` aplikasi (encrypted).
- [ ] SSL Let's Encrypt via certbot (opsional).
- [ ] Verifikasi: situs hidup di PHP 8.3, ganti ke 8.4, dan app legacy PHP 7.4.

## Fase 3 — Deploy Git (GitHub & GitLab) 🟢 Sonnet (mesin deploy ZDT 🔴 Opus)

- [ ] OAuth app GitHub + GitLab; simpan token (encrypted).
- [ ] List repo + pasang deploy key.
- [ ] **Mode "Update biasa" (in-place):** deploy script editable (default `git pull` →
      `composer install --no-dev` → `npm ci && build` → `migrate --force` → restart
      worker → reload fpm).
- [ ] 🔴 **Opus** — **Mode "Zero-downtime":** `releases/<ts>` + symlink `current` atomik
      + `shared/` (.env, storage) + rollback (boleh shell-out ke Deployer).
- [ ] Pilihan `deploy_mode` per aplikasi + editor deploy script di dashboard.
- [ ] Endpoint webhook GitHub/GitLab → auto-deploy on push ke branch target.
- [ ] Halaman riwayat deployment + log + tombol rollback (mode ZDT).

## Fase 4 — Service / Worker / Cron 🟢 Sonnet

- [ ] systemd: start/stop/restart/status via agent + UI.
- [ ] Supervisord: generate program conf untuk queue worker (`queue:work`/Horizon),
      restart, baca status/log dari UI.
- [ ] Cron: crontab terkelola (drop-in `/etc/cron.d/`) dari UI.
- [ ] Manajemen database dari UI: buat/hapus DB & user, atur grants (MySQL/PG/Mongo).

## Fase 5 — Monitoring + Web Terminal (lanjutan) 🟢 Sonnet (web terminal 🔴 Opus)

- [ ] Agent: kumpulkan metrics (`gopsutil`: CPU/RAM/disk/load/net + status service).
- [ ] Kirim metrics periodik → simpan time-series (Postgres/Timescale atau rolling Redis).
- [ ] Dashboard: chart resource per server + multi-server overview.
- [ ] 🔴 **Opus** — Web terminal: agent buka PTY (`creack/pty`) ↔ Gateway ↔ xterm.js (multiplex channel).
- [ ] Audit khusus sesi terminal.

## Lintas-Fase (Keamanan & Kualitas)

- [ ] Audit log untuk semua aksi (siapa, server, perintah).
- [ ] Allowlist/Job bertemplate — hindari shell arbitrer dari UI.
- [ ] mTLS/token rotation untuk agent (opsional, tingkatkan keamanan).
- [ ] Dokumentasi instalasi panel + onboarding server.
- [ ] Uji end-to-end sesuai bagian Verifikasi di `PLAN.md`.
