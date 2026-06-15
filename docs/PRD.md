# PRD — Velink (Server Control Panel)

> Product Requirements Document. Status: Draft v1. Tanggal: 2026-06-15.

## 1. Ringkasan

**Velink** adalah control panel internal untuk mengelola banyak server/VM dari
satu dashboard, terinspirasi RunCloud/Laravel Forge/Ploi. Satu VM "panel" mengelola
banyak server target tanpa perlu masuk ke terminal secara manual.

Fokus utama: mengelola aplikasi **PHP/Laravel** dengan **versi PHP berbeda per
aplikasi**, plus database, Redis, service systemd, supervisord (queue worker),
cronjob, deploy dari Git (GitHub & GitLab), monitoring resource, dan web terminal.

## 2. Masalah yang Diselesaikan

- Mengelola banyak VM aplikasi PHP saat ini butuh SSH manual + perintah berulang
  (install service, atur php-fpm pool, vhost nginx, worker, cron, deploy).
- Rawan salah/tidak konsisten antar server, tidak ada audit, tidak ada visibilitas
  resource terpusat.
- Ganti versi PHP per aplikasi, setup worker, dan deploy memakan waktu dan rawan error.

## 3. Tujuan & Non-Tujuan

### Tujuan
- Satu dashboard untuk provisioning + kelola banyak server/VM.
- Kelola aplikasi PHP dengan **versi PHP per-app (7.4, 8.1, 8.2, 8.3, 8.4)**.
- Kelola database (MySQL/MariaDB, PostgreSQL, MongoDB), Redis, service, supervisord, cron.
- Deploy dari GitHub & GitLab, dengan auto-deploy via webhook.
- Monitoring resource multi-server + web terminal lewat browser.
- Tidak perlu install apa pun di server target secara manual selain **agent** (satu binary).

### Non-Tujuan (untuk versi awal)
- Bukan produk SaaS multi-tenant komersial (ini untuk pemakaian internal/pribadi).
- Tidak menargetkan banyak distro di awal — **Ubuntu LTS saja** dulu.
- Tidak menggantikan orchestrator container (bukan Kubernetes/Docker Swarm).
- Belum ada billing, marketplace, atau white-label.

## 4. Persona & Pemakaian

- **Operator tunggal / tim kecil internal** (pemilik). Punya akses penuh privileged ke
  server. Mengelola puluhan VM, bukan ribuan.
- Pemakaian dari browser; sesekali butuh web terminal untuk debugging.

## 5. Kebutuhan Fungsional (FR)

### FR-1 Manajemen Server
- Tambah server via token + perintah install agent (one-liner).
- Agent dial-out (konek keluar) → tembus NAT/firewall, status online/offline realtime.
- Provisioning: agent menginstall nginx, php-fpm (7.4–8.4), database, redis,
  supervisord, certbot — dipilih dari dashboard.

### FR-2 Aplikasi PHP + Versi PHP per-App
- Buat aplikasi: domain, vhost nginx, php-fpm pool, Linux user terisolasi per-site.
- Pilih/ubah versi PHP per aplikasi (7.4, 8.1, 8.2, 8.3, 8.4).
- Kelola `.env`, SSL (Let's Encrypt/certbot), dan setting dasar PHP per pool.

### FR-3 Database
- Install & kelola **MySQL/MariaDB, PostgreSQL, MongoDB** per server.
- Buat/hapus database & user, atur grants/akses.

### FR-4 Redis
- Install & kelola Redis; lihat status.

### FR-5 Service, Worker, Cron
- Kontrol systemd service (start/stop/restart/status).
- Kelola supervisord: program untuk queue worker (mis. `artisan queue:work`/Horizon),
  restart, lihat status/log.
- Kelola cronjob (crontab terkelola dari UI).

### FR-6 Deploy dari Git (GitHub & GitLab)
- Hubungkan akun GitHub & GitLab (OAuth), list repo, pasang deploy key.
- **Dua mode deploy per aplikasi:**
  - **Update biasa (in-place):** jalankan deploy script (default `git pull` →
    `composer install` → build → `migrate` → restart worker → reload fpm). Cepat,
    sedikit downtime.
  - **Zero-downtime:** strategi `releases/` + symlink `current` + rollback.
- Deploy script bisa diedit dari dashboard.
- Webhook GitHub/GitLab → auto-deploy saat push ke branch target.

### FR-7 Monitoring
- Resource per server: CPU, RAM, disk, load, network; status service.
- Riwayat/time-series + chart di dashboard.

### FR-8 Web Terminal
- Akses terminal server lewat browser (xterm.js), teraudit.

### FR-9 Audit & User
- Semua aksi tercatat (siapa, server mana, perintah apa).
- Login + 2FA.

## 6. Kebutuhan Non-Fungsional (NFR)

- **Keamanan:** agent berjalan privileged → auth token per-server (hashed), TLS
  wajib (agent verifikasi cert panel), opsi mTLS. Tidak ada eksekusi shell arbitrer
  dari UI (Job bertemplate + allowlist; web terminal jalur terpisah & teraudit).
  Rahasia (token Git, password DB, `.env`) **dienkripsi at-rest**. 2FA untuk semua user.
  Panel di balik VPN/firewall. Identifier yang terekspos ke browser/URL (server,
  aplikasi, kredensial git, deployment, serta identifier server di protokol
  agent↔gateway) memakai **UUID**, bukan primary key bigint sekuensial, untuk
  menghindari enumerasi resource.
- **Skala:** target puluhan VM; satu Gateway Go di VM panel cukup.
- **Keandalan:** Job idempotent; agent auto-reconnect; deploy bisa rollback (mode ZDT).
- **Observability:** audit log + log Job per server.
- **OS target:** Ubuntu 22.04/24.04 LTS.

## 7. Ruang Lingkup MVP vs Lanjutan

**MVP (prioritas):**
1. Server + agent + provisioning (termasuk DB Postgres/MySQL/Mongo + Redis).
2. Aplikasi PHP + versi PHP per-app (7.4–8.4).
3. Deploy Git (dua mode) + webhook.
4. Service/worker/cron.

**Fase lanjutan:**
5. Monitoring (time-series + chart).
6. Web terminal (PTY via gateway + xterm.js).

## 8. Kriteria Sukses

- Bisa menambah server baru hanya dengan menjalankan satu perintah install agent.
- Bisa membuat aplikasi Laravel, pilih PHP 8.3, deploy dari GitHub, dan situs hidup —
  semuanya dari dashboard, tanpa SSH.
- Bisa ganti versi PHP app ke 8.4 (atau turun ke 7.4 untuk app legacy) dari UI.
- Bisa membuat queue worker (supervisord) dan cron dari UI.
- Semua aksi tercatat di audit log.

## 9. Asumsi & Batasan

- Server target Ubuntu LTS dengan akses keluar ke panel (untuk dial-out agent).
- Pemakaian internal; tidak ada kebutuhan kepatuhan eksternal di awal.
- Logika deploy zero-downtime boleh memanfaatkan **Deployer** agar tidak menulis ulang.

## 10. Referensi Terkait

- Rencana teknis & arsitektur: [`PLAN.md`](./PLAN.md)
- Checklist pengerjaan: [`TODO.md`](./TODO.md)
