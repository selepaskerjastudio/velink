# coruncloud

Self-hosted server control panel untuk mengelola banyak Ubuntu VM dari satu dashboard — terinspirasi RunCloud/Laravel Forge. Tidak perlu SSH manual: satu agent ringan di setiap server terkelola menerima perintah dari panel lewat WebSocket.

**Fitur utama:**

- Provisioning otomatis: nginx, php-fpm (7.4/8.1/8.2/8.3/8.4), MySQL/MariaDB, PostgreSQL, MongoDB, Redis, supervisord, certbot
- Aplikasi PHP dengan **versi PHP per-app**, vhost nginx, Linux user terisolasi per-site
- Editor `.env`, SSL Let's Encrypt via certbot
- Deploy dari GitHub & GitLab (webhook auto-deploy + deploy manual)
- Queue worker via supervisord, cron terkelola, systemd service control
- Manajemen database & user (MySQL/PG/Mongo)
- Monitoring resource (CPU/RAM/disk/load) realtime per server
- Audit log semua aksi

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
| **Panel** | Laravel 12 + Inertia + React. UI + logika bisnis + database. Jalan di "VM panel". |
| **Gateway** | Binary Go ringan. Terminator WebSocket antara agent dan panel. Jalan di VM panel yang sama. |
| **Agent** | Binary Go statis. Di-install di setiap VM terkelola. Dial-out ke gateway (NAT-friendly). |

---

## Kebutuhan Panel Server

> Panel dan gateway diinstall di **satu VM khusus** (tidak tercampur dengan VM terkelola).

- Ubuntu 22.04 / 24.04 LTS (atau macOS untuk dev lokal)
- PHP 8.2+ dengan ekstensi: `pdo`, `pdo_pgsql`, `pgsql`, `redis`, `pcntl`, `mbstring`, `xml`, `curl`, `zip`
- Composer 2
- Node.js 20+ dan npm
- PostgreSQL 15+ (atau SQLite untuk dev)
- Redis 7+
- Go 1.22+ (untuk build gateway)

---

## 1. Setup Panel

### 1.1 Clone & install dependensi

```bash
git clone https://github.com/yourname/coruncloud.git
cd coruncloud/panel

composer install
npm install
```

### 1.2 Konfigurasi environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` sesuai kebutuhan:

```dotenv
APP_NAME=coruncloud
APP_URL=https://panel.example.com       # URL publik panel (wajib, dipakai di perintah install agent)
APP_ENV=production
APP_DEBUG=false

# Database — PostgreSQL (produksi)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=coruncloud
DB_USERNAME=coruncloud
DB_PASSWORD=ganti_ini

# Session & queue via database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Reverb (WebSocket browser)
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=coruncloud
REVERB_APP_KEY=ganti_ini
REVERB_APP_SECRET=ganti_ini
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=https

# Gateway
GATEWAY_SECRET=rahasia_panjang_acak_64char   # harus sama dengan GATEWAY_PANEL_SECRET di gateway/.env
GATEWAY_PUBLIC_URL=wss://panel.example.com:9090   # URL WSS yang bisa dicapai agent dari internet
```

### 1.3 Migrasi database

```bash
php artisan migrate --force
```

### 1.4 Build aset frontend

```bash
npm run build
```

### 1.5 Jalankan di production

Gunakan **supervisor** atau **systemd** untuk masing-masing proses. Contoh dengan supervisor:

**`/etc/supervisor/conf.d/coruncloud-panel.conf`:**
```ini
[program:coruncloud-queue]
command=php /path/to/panel/artisan queue:work --tries=3 --sleep=3
directory=/path/to/panel
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/coruncloud-queue.log

[program:coruncloud-reverb]
command=php /path/to/panel/artisan reverb:start
directory=/path/to/panel
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/coruncloud-reverb.log
```

Serve HTTP via **nginx + php-fpm** (Laravel standard), root ke `/path/to/panel/public`.

---

## 2. Setup Gateway

Gateway adalah binary Go yang berjalan di VM panel yang sama.

### 2.1 Build

```bash
cd gateway
go build -o ../coruncloud-gateway ./cmd/gateway
```

Atau download binary dari release page (jika tersedia).

### 2.2 Konfigurasi

```bash
cp .env.example .env
```

Edit `gateway/.env`:

```dotenv
GATEWAY_LISTEN=:9090                              # port WSS yang didengarkan agent
GATEWAY_PANEL_URL=http://127.0.0.1:80             # URL internal panel (loopback)
GATEWAY_PANEL_SECRET=rahasia_panjang_acak_64char  # harus sama dengan GATEWAY_SECRET di panel/.env
GATEWAY_REDIS_ADDR=127.0.0.1:6379
GATEWAY_REDIS_PASSWORD=
GATEWAY_REDIS_DB=0
GATEWAY_PRESENCE_TTL=90
```

### 2.3 Jalankan sebagai systemd service

**`/etc/systemd/system/coruncloud-gateway.service`:**
```ini
[Unit]
Description=coruncloud Gateway
After=network.target

[Service]
EnvironmentFile=/path/to/gateway/.env
ExecStart=/path/to/coruncloud-gateway
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now coruncloud-gateway
```

---

## 3. Akun pertama

Buka `https://panel.example.com/register` untuk membuat akun admin pertama.

> Setelah akun pertama dibuat, disarankan menonaktifkan registrasi publik (atur `APP_REGISTRATION=false` atau batasi via middleware).

---

## 4. Tambah Server Terkelola

### 4.1 Dari dashboard

1. Login ke panel → klik **Servers** → **Add Server**
2. Isi nama server, hostname/IP
3. Klik **Add** — panel menampilkan perintah install one-liner

### 4.2 Install agent di VM target

Jalankan perintah yang disalin dari panel **di VM target** (Ubuntu 22.04/24.04) sebagai root:

```bash
curl -fsSL https://panel.example.com/install/agent.sh | sudo bash -s -- \
    --token=<TOKEN> \
    --panel=https://panel.example.com \
    --gateway=wss://panel.example.com:9090 \
    --server-id=<UUID>
```

Installer akan:
- Download binary `coruncloud-agent` ke `/usr/local/bin/`
- Tulis konfigurasi ke `/etc/coruncloud/agent.env`
- Daftarkan dan aktifkan systemd unit `coruncloud-agent`

Setelah berhasil, status server di panel berubah menjadi **online** dalam beberapa detik.

---

## 5. Cara Menggunakan

### Provisioning komponen server

Masuk ke halaman server → tab **Provisioning**. Centang komponen yang diinginkan (nginx, PHP versi tertentu, MySQL, PostgreSQL, dll.) lalu klik **Provision**. Progress ditampilkan realtime lewat WebSocket.

### Buat aplikasi PHP

1. Di halaman server → klik **New Application**
2. Isi nama, domain (mis. `app.example.com`), dan versi PHP
3. Panel otomatis membuat: Linux user terisolasi, vhost nginx, php-fpm pool, home directory

**Catatan:** domain harus sudah diarahkan ke IP server target sebelum provisioning.

### Ganti versi PHP per-app

Halaman aplikasi → kartu **PHP version** → pilih versi → **Switch PHP version**. Hanya pool php-fpm yang diganti; nginx tidak direload.

### SSL / Let's Encrypt

Halaman aplikasi → kartu **SSL / HTTPS** → **Enable SSL**. Panel menjalankan `certbot --nginx` via agent. Pastikan:
- Domain sudah resolve ke server
- Port 80 terbuka (certbot HTTP challenge)
- `certbot` sudah diinstall (via Provisioning → nginx + certbot)

### Deploy dari Git

1. Tambah **Git Credential** (Personal Access Token GitHub/GitLab) di Settings → Git Credentials
2. Di halaman aplikasi → kartu **Deploy** → pilih credential → cari/isi repository (format `owner/repo`) → isi branch → edit deploy script jika perlu → **Save deploy settings**
3. Klik **Deploy now** untuk deploy manual

**Deploy script** default:
```bash
cd {{ root_path }}
git fetch origin
git reset --hard origin/{{ branch }}
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Auto-deploy via webhook

Setelah repository dikonfigurasi, halaman aplikasi menampilkan kartu **Auto-deploy webhook**:

**GitHub:**
- Pergi ke repo → Settings → Webhooks → Add webhook
- **Payload URL:** salin dari panel
- **Content type:** `application/json`
- **Secret:** salin dari panel
- Events: pilih `Just the push event`

**GitLab:**
- Pergi ke repo → Settings → Webhooks → Add new webhook
- **URL:** salin dari panel (URL GitLab)
- **Secret token:** salin dari panel (secret yang sama)
- Events: centang `Push events`

Push ke branch yang dikonfigurasi akan men-trigger deploy otomatis.

### Manajemen database

Halaman server → **Databases** → **New Database**. Pilih engine (MySQL/MariaDB, PostgreSQL, MongoDB), isi nama. Buat user dan atur grants di tab **Database Users**. Password ditampilkan sekali saat pembuatan.

### Queue worker (supervisord)

Halaman aplikasi → **Workers** → **New Worker**. Isi nama program dan perintah (mis. `php artisan queue:work --queue=default`). Panel men-generate konfigurasi supervisord dan me-reload supervisor via agent.

### Cron

Halaman server → **Cron** → **New Cron Job**. Isi ekspresi cron dan perintah. Panel menulis drop-in ke `/etc/cron.d/coruncloud` via agent.

### Service systemd

Halaman server → **Services**. Tampilkan service yang sudah diinstall, start/stop/restart lewat tombol.

### Monitoring

Halaman server menampilkan chart **CPU / RAM / Disk** realtime (data dari agent setiap 30 detik, disimpan 2 jam terakhir).

### Audit log

Menu **Audit log** di sidebar menampilkan riwayat semua aksi (siapa, kapan, server mana, aksi apa).

---

## Development lokal

```bash
cd panel
cp .env.example .env
php artisan key:generate
php artisan migrate

# Jalankan semua service sekaligus
composer run dev
```

`composer run dev` menjalankan `php artisan serve`, `queue:listen`, `pail`, dan `npm run dev` secara bersamaan.

**Test:**
```bash
php artisan test
```

**Gateway (dev):**
```bash
cd gateway
cp .env.example .env
# Isi GATEWAY_PANEL_SECRET = nilai GATEWAY_SECRET di panel/.env
set -a && . ./.env && set +a
go run ./cmd/gateway
```

---

## Struktur monorepo

```
/panel       Laravel 12 + Inertia 2 + React 19 (control panel)
/gateway     Go — WebSocket gateway (bridge agent ↔ panel via Redis)
/agent       Go — agent binary yang diinstall di setiap server terkelola
/provision   Template config nginx/php-fpm/supervisor (referensi)
/installer   Script curl|bash one-liner untuk install agent
/docs        PRD, PLAN, TODO
```

---

## Lisensi

Proyek ini untuk pemakaian internal/pribadi. Lisensi belum ditetapkan.
