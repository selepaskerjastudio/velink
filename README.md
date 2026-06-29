# Velink

**Velink** adalah control panel server self-hosted untuk mengelola banyak Ubuntu VM dari satu dashboard — terinspirasi RunCloud / Laravel Forge. Tanpa SSH manual: satu agent ringan di tiap server terkelola menerima perintah dari panel lewat WebSocket, lalu mengeksekusi provisioning, deploy, dan operasi lain secara realtime.

> **Mau langsung pasang?** Lihat **[docs/INSTALL.md](docs/INSTALL.md)**, atau jalankan installer cepat di bawah.

---

## Fitur

- **Multi-server** dari satu panel — kelola puluhan VM tanpa login SSH satu per satu.
- **Provisioning otomatis**: nginx, php-fpm (7.4 / 8.1 / 8.2 / 8.3 / 8.4), MySQL/MariaDB, PostgreSQL, MongoDB, Redis, supervisord, certbot.
- **Aplikasi PHP** dengan **versi PHP per-app**, vhost nginx, Linux user terisolasi per-site, editor `.env`.
- **SSL Let's Encrypt** — challenge HTTP-01 maupun **DNS-01 via Cloudflare**.
- **Manajemen DNS Cloudflare** — A record otomatis saat app dibuat.
- **Deploy GitHub & GitLab** — manual + **webhook auto-deploy**, dengan **viewer log deploy realtime** (warna ANSI).
- **Backup & restore** — dump DB + file aplikasi, simpan lokal atau **S3**, dengan penjadwalan & retensi.
- **Notifikasi** — email / Slack / Discord / Telegram saat resource alert (CPU/RAM/disk).
- **Keamanan server** — firewall UFW + fail2ban dari panel.
- **Web terminal** (PTY) per server, langsung di browser.
- **Database & user** management (MySQL / PostgreSQL / MongoDB).
- **Queue worker** (supervisord), **cron** terkelola, kontrol **service systemd**.
- **SSH key** & **system user** management.
- **Monitoring** CPU / RAM / disk realtime per server.
- **Audit log** semua aksi (siapa, kapan, server mana, aksi apa).

---

## Arsitektur

```
Browser ←→ Reverb (WebSocket) ←→ Panel (Laravel)
                                        │
                                   Redis pub/sub
                                        │
                                  Gateway (Go) ←──→ Agent (Go) [di setiap VM terkelola]
```

Tiga komponen:

| Komponen | Deskripsi |
|---|---|
| **Panel** | Laravel 12 + Inertia 2 + React 19. UI, logika bisnis, database, orkestrasi job. Jalan di **VM panel**. |
| **Gateway** | Binary Go ringan & stateless. Terminator WebSocket antara agent dan panel. Jalan di VM panel yang sama. |
| **Agent** | Binary Go statis di setiap **VM terkelola**. Dial-out ke gateway (NAT/firewall friendly), eksekusi job, stream output. |

### Cara kerja (alur job)

1. Panel membuat record `AgentJob` (state `pending`) dan mem-publish ke channel Redis `velink:gateway:dispatch`.
2. Gateway merutekan job ke agent yang tepat berdasarkan `server_id`.
3. Agent mengeksekusi (`shell`, `write_file`, atau `render_config`) dan men-stream stdout/stderr/exit-code balik ke gateway.
4. Panel memperbarui status job dan broadcast progress ke browser via Reverb — semuanya realtime.

---

## Instalasi

Velink dipasang di **VM panel khusus** (panel + gateway), terpisah dari VM yang dikelola.

**Instalasi cepat** (wizard interaktif, VM Ubuntu kosong → panel siap pakai):

```bash
curl -fsSL https://raw.githubusercontent.com/selepaskerjastudio/velink/main/installer/panel-install.sh | sudo bash
```

Installer memasang PHP/Composer/Node/Go/PostgreSQL/Redis/nginx/certbot, generate semua secret, build panel + gateway, pasang systemd service, dan terbitkan SSL. Setelah selesai, buka `https://<domain>/register`.

Panduan lengkap (pra-instalasi, non-interaktif, instalasi manual, update, dev lokal): **[docs/INSTALL.md](docs/INSTALL.md)**.

---

## Cara menggunakan

### Provisioning komponen server
Halaman server → tab **Provisioning** → centang komponen (nginx, PHP versi tertentu, MySQL, PostgreSQL, dll) → **Provision**. Progress realtime via WebSocket.

### Buat aplikasi PHP
Halaman server → **New Application** → isi nama, domain (mis. `app.example.com`), versi PHP. Panel otomatis membuat Linux user terisolasi, vhost nginx, php-fpm pool, dan home directory.

> Domain harus sudah diarahkan ke IP server target sebelum provisioning.

### Ganti versi PHP per-app
Halaman aplikasi → kartu **PHP version** → pilih versi → **Switch PHP version**. Hanya pool php-fpm yang diganti; nginx tidak direload.

### SSL / Let's Encrypt
Halaman aplikasi → kartu **SSL / HTTPS** → **Enable SSL**. Mendukung HTTP-01 (perlu port 80 terbuka) atau DNS-01 via Cloudflare (kalau token Cloudflare tersambung di Settings).

### Deploy dari Git
1. Tambah **Git Credential** (PAT GitHub/GitLab) di Settings → Git Credentials.
2. Halaman aplikasi → kartu **Deploy** → pilih credential, isi repository (`owner/repo`) + branch, sesuaikan deploy script → **Save**.
3. **Deploy now** untuk deploy manual; log tampil realtime dengan warna.

### Auto-deploy via webhook
Setelah repo dikonfigurasi, kartu **Auto-deploy webhook** menampilkan Payload URL + Secret. Pasang di repo (GitHub: Settings → Webhooks, content type `application/json`, event push; GitLab: Webhooks, secret token, push events). Push ke branch yang dikonfigurasi → deploy otomatis.

### Backup & restore
Halaman aplikasi → **Backups** → atur jadwal (harian/mingguan/bulanan), retensi, dan target (lokal/S3). **Backup now** untuk manual. Restore bersifat destruktif (overwrite file + import DB) dan butuh konfirmasi.

### Database, worker, cron, service
- **Databases**: buat DB (MySQL/PG/Mongo) + user & grants. Password ditampilkan sekali.
- **Workers**: program supervisord (mis. `php artisan queue:work`).
- **Cron**: ekspresi cron + perintah → drop-in `/etc/cron.d/velink`.
- **Services**: start/stop/restart systemd service.

### Keamanan, terminal & monitoring
- **Security**: kelola firewall UFW + fail2ban (ban/unban IP).
- **Web terminal**: shell PTY ke server langsung dari browser.
- **Monitoring**: chart CPU/RAM/disk realtime (sampel agent tiap 30 detik).
- **Notifikasi**: kirim alert ke email/Slack/Discord/Telegram di Settings → Notifications.
- **Audit log**: riwayat semua aksi di sidebar.

---

## Update & Maintenance

### Update panel + gateway

Jalankan di **VM panel**:

```bash
cd /root/velink && bash deploy.sh
```

`deploy.sh` otomatis: `git pull` → `composer install` → `npm build` → `migrate` → rebuild gateway binary → rebuild agent binaries → restart semua service.

> **Prasyarat**: Go harus terinstall di VM panel (`sudo apt-get install -y golang-go`). Tanpa Go, gateway dan agent binaries tidak akan di-rebuild (akan ada pesan warning).

### Update agent di server terkelola

Setiap kali panel di-update, agent binaries baru otomatis di-build dan tersedia di:
```
/install/bin/agent-linux-amd64-latest
/install/bin/agent-linux-arm64-latest
```

**Untuk update agent di setiap VM terkelola**, SSH ke server tersebut lalu jalankan:

```bash
# Hentikan agent lama
sudo systemctl stop velink-agent

# Download binary baru dari panel (ganti domain panel Anda)
sudo curl -fsSL -o /usr/local/bin/velink-agent \
    https://panel.domainanda.com/install/bin/agent-linux-amd64-latest

# Set permission + jalankan ulang
sudo chmod +x /usr/local/bin/velink-agent
sudo systemctl start velink-agent
```

> Untuk server ARM64 (mis. Raspberry Pi, Graviton), ganti `amd64` dengan `arm64`.

**Verifikasi agent sudah ter-update:**

```bash
# Cek agent berjalan
sudo systemctl status velink-agent

# Cek panel menampilkan server sebagai "online"
```

### Update agent via installer (fresh install)

Untuk server baru yang belum punya agent, gunakan perintah install dari halaman **Servers → Connect** di panel:

```bash
curl -fsSL https://panel.domainanda.com/install/agent.sh | sudo bash -s -- \
    --token=TOKEN_DARI_PANEL --server-id=UUID_DARI_PANEL
```

---

## Struktur monorepo

```
/panel       Laravel 12 + Inertia 2 + React 19 (control panel)
/gateway     Go — WebSocket gateway (bridge agent ↔ panel via Redis)
/agent       Go — agent binary di setiap server terkelola
/provision   Template nginx/php-fpm/supervisor + script provisioning
/installer   Script curl|bash: panel-install.sh (panel) + agent.sh (agent)
/docs        INSTALL.md, PRD.md, PLAN.md, TODO.md
```

Untuk kontributor & agent coding, lihat [CLAUDE.md](CLAUDE.md) / [AGENTS.md](AGENTS.md).

---

## Lisensi

Proyek ini untuk pemakaian internal/pribadi. Lisensi belum ditetapkan.
