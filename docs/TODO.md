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
