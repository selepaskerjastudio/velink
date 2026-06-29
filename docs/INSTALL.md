# Instalasi Velink

Panduan instalasi lengkap: pra-instalasi, instalasi cepat (otomatis), instalasi manual, dan menambah server terkelola.

---

## Konsep: dua jenis mesin

Velink memisahkan **VM panel** (control plane) dari **VM terkelola** (server yang dikelola). Installer-nya berbeda:

| Mesin | Isi | Cara install |
|---|---|---|
| **VM Panel** | Panel (Laravel) + Gateway (Go) + nginx + PostgreSQL + Redis | `installer/panel-install.sh` (sekali di VM khusus) |
| **VM Terkelola** | Agent (Go) + stack aplikasi (nginx, php-fpm, db, dll) | `agent.sh` one-liner (di setiap server, di-generate panel) |

> Panel dan gateway selalu di **satu VM khusus**, terpisah dari VM yang dikelola.

---

## Pra-instalasi (VM Panel)

Sebelum menjalankan installer, siapkan:

1. **VM Ubuntu 22.04 / 24.04 LTS** bersih, akses `root` (atau `sudo`).
2. **Domain** (mis. `panel.example.com`) dengan **DNS A record sudah mengarah ke IP VM** — wajib untuk SSL (certbot).
3. **Port 80 & 443 terbuka** di firewall/security group (HTTP challenge + HTTPS/WSS).
4. Spesifikasi minimum disarankan: 2 vCPU, 2 GB RAM, 20 GB disk.

### Paket yang dibutuhkan

Installer cepat memasang semuanya otomatis. Untuk **instalasi manual**, siapkan:

- PHP 8.2+ dengan ekstensi: `pdo_pgsql`, `pgsql`, `redis`, `pcntl`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `gd`, `intl`
- Composer 2
- Node.js 20+ dan npm
- PostgreSQL 15+
- Redis 7+
- Go 1.24+ (build gateway + binary agent)
- nginx, certbot (+ `python3-certbot-dns-cloudflare` untuk SSL DNS-01)

---

## Instalasi cepat (rekomendasi)

Satu wizard interaktif mengubah VM kosong jadi panel yang siap pakai:

```bash
curl -fsSL https://raw.githubusercontent.com/selepaskerjastudio/velink/main/installer/panel-install.sh | sudo bash
```

Wizard menanyakan: domain, email (SSL), repo/branch, direktori install. Lalu otomatis menjalankan 12 langkah:

1. Install paket sistem (PHP-FPM, Composer, Node 20, Go, PostgreSQL, Redis, nginx, certbot)
2. Buat database + user PostgreSQL
3. Clone repository
4. Generate `.env` panel & gateway (semua secret acak; `GATEWAY_SECRET` cocok di dua sisi, `APP_KEY`, kunci Reverb)
5. `composer install` + `npm ci` + `npm run build`
6. Migrasi database
7. Build binary gateway → `/usr/local/bin/velink-gateway`
8. **Pre-build binary agent** (linux amd64 + arm64) → `storage/app/agent-bins` (supaya "Add Server" langsung bisa)
9. Tulis vhost nginx (`/app/` → Reverb 8080, `/agent/connect` → gateway 8081)
10. Pasang 4 systemd unit (`velink-queue`, `velink-reverb`, `velink-agent-listen`, `velink-gateway`)
11. Terbitkan SSL via certbot
12. Selesai — tampilkan URL register & status service

### Non-interaktif (otomasi / CI)

```bash
sudo bash panel-install.sh --non-interactive \
    --domain panel.example.com \
    --email you@example.com
```

Flag tersedia: `--domain` `--email` `--repo` `--branch` `--dir` `--db-pass` `--php` `--go-version` `--no-ssl` `--non-interactive`.

- Default direktori: `/opt/velink`
- Default DB password: acak (ditampilkan di akhir, tersimpan di `panel/.env`)
- Log lengkap: `/var/log/velink-panel-install.log`

> `--no-ssl` melewati certbot (HTTP saja). Agent butuh `wss://` (TLS), jadi **terbitkan SSL sebelum menambah server**.

Setelah selesai, buka `https://<domain>/register` untuk membuat akun admin pertama.

---

## Instalasi manual

Untuk setup bertahap atau kustom. Contoh memakai `INSTALL_DIR=/opt/velink` dan service berjalan sebagai `www-data`.

### 1. Panel (Laravel)

```bash
git clone https://github.com/selepaskerjastudio/velink.git /opt/velink
cd /opt/velink/panel
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_NAME=Velink
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.example.com          # URL publik panel (dipakai di perintah install agent)

# Database — PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=velink
DB_USERNAME=velink
DB_PASSWORD=ganti_ini

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=reverb

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Reverb — server bind 8080; klien konek lewat nginx (443/wss)
REVERB_APP_ID=velink
REVERB_APP_KEY=ganti_ini
REVERB_APP_SECRET=ganti_ini
REVERB_HOST=panel.example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Gateway — agent konek ke wss://domain/agent/connect (nginx → 127.0.0.1:8081)
GATEWAY_SECRET=rahasia_panjang_acak_64char   # harus sama dengan GATEWAY_PANEL_SECRET di gateway/.env
GATEWAY_PUBLIC_URL=wss://panel.example.com
```

Buat database PostgreSQL, lalu migrasi & build:

```bash
sudo -u postgres psql -c "CREATE ROLE velink LOGIN PASSWORD 'ganti_ini';"
sudo -u postgres psql -c "CREATE DATABASE velink OWNER velink;"

php artisan migrate --force
npm ci && npm run build
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

### 2. Gateway (Go)

Gateway berjalan di VM panel yang sama.

```bash
cd /opt/velink/gateway
go build -o /usr/local/bin/velink-gateway ./cmd/gateway
cp .env.example .env
```

Edit `gateway/.env`:

```dotenv
GATEWAY_LISTEN=:8081                              # port internal (di-proxy nginx). HARUS beda dari Reverb (8080)
GATEWAY_PANEL_URL=http://127.0.0.1:80             # panel internal (loopback)
GATEWAY_PANEL_SECRET=rahasia_panjang_acak_64char  # = GATEWAY_SECRET di panel/.env
GATEWAY_REDIS_ADDR=127.0.0.1:6379
GATEWAY_REDIS_PASSWORD=
GATEWAY_REDIS_DB=0
GATEWAY_PRESENCE_TTL=90
```

> ⚠️ **Port jangan bentrok.** Reverb bind `REVERB_SERVER_PORT=8080`, gateway `GATEWAY_LISTEN=:8081`. Keduanya di-proxy nginx lewat 443.

### 3. nginx

`/etc/nginx/sites-available/velink` (root ke `panel/public`):

```nginx
server {
    listen 80;
    server_name panel.example.com;
    root /opt/velink/panel/public;
    index index.php;
    client_max_body_size 100M;

    # Reverb (browser WS). Trailing slash WAJIB — tanpa itu menangkap /apps/ & /applications/.
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 86400;
    }

    # Gateway (agent WS)
    location /agent/connect {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 86400;
    }

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
ln -sf /etc/nginx/sites-available/velink /etc/nginx/sites-enabled/velink
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
certbot --nginx -d panel.example.com --agree-tos -m you@example.com --redirect
```

### 4. systemd

Empat unit wajib jalan. WorkingDirectory = `/opt/velink/panel`, jalan sebagai `www-data`:

| Unit | ExecStart |
|---|---|
| `velink-queue.service` | `php8.3 artisan queue:work --tries=3 --sleep=3` |
| `velink-reverb.service` | `php8.3 artisan reverb:start --host=0.0.0.0 --port=8080` |
| `velink-agent-listen.service` | `php8.3 artisan agent:listen` |
| `velink-gateway.service` | `/usr/local/bin/velink-gateway` (EnvironmentFile = `/opt/velink/gateway/.env`) |

Contoh `velink-agent-listen.service`:

```ini
[Unit]
Description=Velink agent listener
After=network.target redis-server.service
[Service]
User=www-data
WorkingDirectory=/opt/velink/panel
ExecStart=/usr/bin/php8.3 artisan agent:listen
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
```

> `velink-agent-listen` **wajib** — tanpa ini status server tidak pernah berubah online/offline.

```bash
chown -R www-data:www-data /opt/velink
systemctl daemon-reload
systemctl enable --now velink-queue velink-reverb velink-agent-listen velink-gateway
```

---

## Akun pertama

Buka `https://panel.example.com/register` untuk membuat akun admin.

> Registrasi **menutup otomatis** begitu akun pertama dibuat — middleware `RegistrationEnabled` mem-block `/register` (403) selama sudah ada user. Tidak perlu langkah manual.

---

## Menambah server terkelola (agent)

1. Login panel → **Servers** → **Add Server** → isi nama + hostname/IP → **Add**.
2. Panel menampilkan perintah one-liner. Jalankan di **VM target** (Ubuntu 22.04/24.04) sebagai root:

```bash
curl -fsSL https://panel.example.com/install/agent.sh | sudo bash -s -- \
    --token=<TOKEN> --server-id=<UUID>
```

Installer agent: download binary ke `/usr/local/bin/velink-agent`, tulis config `/etc/velink/agent.env` (chmod 600), pasang systemd unit `velink-agent`, arm provisioning, start. Status server di panel jadi **online** dalam beberapa detik.

3. Di halaman server → tab **Provisioning** → centang komponen (nginx, PHP, MySQL/PG/Mongo, Redis, dll) → **Provision**. Progress realtime via WebSocket.

---

## Update panel

Cara termudah — script `deploy.sh` di root repo (jalankan di VM panel sebagai root):

```bash
/opt/velink/deploy.sh            # pull, build, migrate, restart service panel
/opt/velink/deploy.sh --gateway  # plus rebuild & restart gateway (kalau /gateway berubah)
```

Atau manual:

```bash
cd /opt/velink && git pull
cd panel
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
systemctl restart velink-queue velink-reverb velink-agent-listen
```

> Gateway tidak perlu restart kecuali folder `/gateway` berubah.

---

## Troubleshooting

### Server tetap "offline" setelah pasang agent
- Cek `velink-agent-listen` jalan di VM panel: `systemctl status velink-agent-listen`. Tanpa service ini status server tidak pernah berubah.
- Cek gateway hidup: `systemctl status velink-gateway` + log `journalctl -u velink-gateway -f`.
- Di VM target: `systemctl status velink-agent` + `journalctl -u velink-agent -f`. Cari error TLS/koneksi.
- Agent butuh `wss://` (TLS) ke `https://<domain>/agent/connect`. Kalau panel dipasang `--no-ssl`, agent **tidak bisa konek** — terbitkan SSL dulu.
- Pastikan `GATEWAY_SECRET` di `panel/.env` = `GATEWAY_PANEL_SECRET` di `gateway/.env`. Beda → verifikasi token gagal.

### Certbot gagal terbitkan SSL
- DNS A record domain harus sudah mengarah ke IP VM panel **sebelum** certbot jalan.
- Port 80 harus terbuka (HTTP-01 challenge). Cek security group / `ufw status`.
- Ulang manual: `certbot --nginx -d panel.example.com --agree-tos -m you@example.com --redirect`.

### 502 Bad Gateway di browser
- Socket php-fpm di vhost harus cocok versi PHP terpasang (`/run/php/php8.3-fpm.sock`). Cek `systemctl status php8.3-fpm`.
- `nginx -t` untuk validasi config, lalu `systemctl reload nginx`.

### WebSocket browser tidak connect / route 404 aneh
- Block nginx **wajib** `location /app/` dengan trailing slash. Tanpa slash, `/app` menangkap `/apps/` & `/applications/` lalu salah rute ke Reverb.
- Cek `velink-reverb` jalan (port 8080).

### Port bentrok
- Reverb `REVERB_SERVER_PORT=8080`, gateway `GATEWAY_LISTEN=:8081`. Harus beda. `ss -ltnp | grep -E '808[01]'` untuk lihat pemakai port.

### Lihat log
```bash
journalctl -u velink-queue -u velink-reverb -u velink-agent-listen -u velink-gateway -f
tail -f /var/log/velink-panel-install.log   # log installer
```

---

## Development lokal

```bash
cd panel
cp .env.example .env      # default SQLite — tanpa PostgreSQL
php artisan key:generate
php artisan migrate
composer run dev          # serve + queue:listen + pail + vite sekaligus
php artisan test          # jalankan test (Pest)
```

Gateway (dev):

```bash
cd gateway
cp .env.example .env      # isi GATEWAY_PANEL_SECRET = GATEWAY_SECRET di panel/.env
set -a && . ./.env && set +a
go run ./cmd/gateway
```
