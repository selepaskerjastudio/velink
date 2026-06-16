# Analisis RunCloud vs Velink

> Dokumen ini membandingkan fitur RunCloud (dari screenshot `docs/runcloud/`) dengan
> implementasi Velink saat ini. Digunakan sebagai acuan pengerjaan Fase 6.
> Tanggal: 2026-06-16.

---

## 1. Server Dashboard

**Screenshot:** `01.manage.runcloud.io_..._dashboard.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Metric card: Load | ✓ | ✓ | ✅ |
| Metric card: Memory (used/total GB + bar) | ✓ | ✓ | ✅ |
| Metric card: Disk (used/total GB + bar) | ✓ | ✓ | ✅ |
| Metric card: **Uptime** | ✓ "3m 12d 12h" | ✗ (ada CPU%) | ❌ Ganti CPU → Uptime |
| Count strip (Web Apps, DB, Cron, Supervisor) | ✓ | ✓ | ✅ |
| Tabel "Latest Web Applications" | ✓ (name, URL, status, owner, PHP) | ✓ (name, domain, PHP, status) | ⚠️ Tidak ada Owner |
| Tombol "Deploy New Web App" (prominent) | ✓ kanan atas | ✗ tombol kecil di header tabel | ⚠️ Kurang prominent |

**Perubahan yang diperlukan:**
- Agent kirim `uptime_seconds` di payload metrics → kolom baru di `server_metrics`
- Dashboard ganti card CPU → Uptime (format humanized: "3d 12h 4m")
- CPU tetap di chart monitoring
- Tambah kolom "Owner" (linux_user) di tabel latest web applications

---

## 2. Monitoring

**Screenshot:** `02.manage.runcloud.io_..._monitoring.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Halaman dedicated `/monitoring` | ✓ | ✗ chart hanya di dashboard | ❌ Belum ada |
| Donut gauge Memory % | ✓ | ✗ | ❌ |
| Donut gauge Disk % | ✓ | ✗ | ❌ |
| Chart Load over time | ✓ | ✓ (di dashboard) | ⚠️ Pindahkan ke halaman dedicated |
| Chart Memory over time | ✓ | ✓ (di dashboard, gabung CPU) | ⚠️ Pisahkan |
| Chart Disk over time | ✓ | ✗ | ❌ |
| Time range selector (Hour/Day) | ✓ | ✗ | ❌ |
| Tab: Top Process | ✓ | ✗ | ❌ (butuh data agent) |
| Tab: Slow Query | ✓ | ✗ | ❌ (butuh integrasi DB) |
| Tab: Storage | ✓ | ✗ | ❌ (butuh data agent) |
| "Last checked" indicator | ✓ | ✗ | ❌ |

**Perubahan yang diperlukan:**
- Buat halaman `servers/monitoring.tsx` + route + controller method
- Fix nav sidebar "Monitoring" (saat ini tidak punya route yang benar)
- Pindahkan chart CPU+RAM dari `show.tsx` ke `monitoring.tsx` + tambah chart Disk
- Tambah donut/radial gauge untuk Memory dan Disk
- Tambah time range selector (1h / 6h / 24h) → query `server_metrics` dengan filter
- Tab "Top Process" dan "Storage" bisa kosong dulu (placeholder)

---

## 3. Web Applications (List per Server)

**Screenshot:** `03.manage.runcloud.io_..._webapps.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Halaman list dedicated per server | ✓ paginated 20/hal | ✗ hanya "Latest 10" di dashboard | ❌ Belum ada |
| Kolom Owner (linux_user) | ✓ | ✗ | ❌ |
| Search | ✓ | ✗ | ❌ |
| Filter | ✓ | ✗ | ❌ |
| Sort By | ✓ | ✗ | ❌ |
| Pagination | ✓ "Showing 1 to 20 of 28" | ✗ | ❌ |
| Tombol "Deploy New Web App" | ✓ prominent kanan atas | ✓ ada tapi kecil | ⚠️ |

**Bug yang ditemukan:** Nav sidebar "Web Applications" saat ini URL-nya sama dengan Dashboard
(`/servers/{id}`) — duplikat. Harus difix ke `/servers/{id}/applications`.

**Perubahan yang diperlukan:**
- Buat route `GET servers/{server}/applications` → `ApplicationController@serverIndex`
- Buat halaman `resources/js/pages/servers/applications.tsx`
- Fix nav sidebar URL "Web Applications"
- Tambah kolom Owner di tabel

---

## 4. Databases

**Screenshot:** `04.manage.runcloud.io_..._databases.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Tabs: Databases / Database Users satu halaman | ✓ | ✗ halaman terpisah | ⚠️ UX kurang baik |
| Tab: phpMyAdmin (link external) | ✓ | ✗ | ❌ Low priority |
| Kolom "Added On" (created_at) | ✓ | ✗ | ❌ |
| Kolom "Collation" | ✓ | ✗ | ❌ |
| Search | ✓ | ✗ | ❌ |

**Perubahan yang diperlukan:**
- Gabungkan `databases.tsx` dan `database-users.tsx` ke satu halaman dengan tabs (atau setidaknya
  tampilkan keduanya dari sidebar dalam satu section)
- Tampilkan kolom `created_at` ("Added On") dan `collation` di tabel database

---

## 5. Services

**Screenshot:** `05.manage.runcloud.io_..._services.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Kolom Processor Usage % per service | ✓ | ✗ | ❌ Butuh data agent |
| Kolom Memory Usage per service | ✓ | ✗ | ❌ Butuh data agent |
| Icon per jenis service (nginx, redis, dll) | ✓ | ✗ | ❌ Nice-to-have |
| Status sebagai toggle button | ✓ Running/Stopped button | Badge + dropdown | ⚠️ Minor UX |

**Catatan:** RunCloud menampilkan per-process resource usage. Untuk Velink, agent perlu
menjalankan `systemctl show --property=MainPID` lalu baca `/proc/{pid}/status` untuk
memory, dan `/proc/{pid}/stat` untuk CPU. Ini cukup kompleks — bisa di-scope ke Fase 6 lanjutan.

---

## 6. Cron Jobs

**Screenshot:** `06.manage.runcloud.io_..._cronjobs.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Tabel: Job Name, Run As, Command, Time, Status | ✓ | ✓ hampir sama | ✅ |
| Tombol Rebuild | ✓ | ✓ (toggle) | ✅ |
| Status Active badge | ✓ | ✓ | ✅ |
| Search | ✓ | ✗ | ❌ Minor |

**Gap minimal** — cron sudah cukup parity.

---

## 7. Supervisor (Workers)

**Screenshot:** `07.manage.runcloud.io_..._supervisor.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Kolom: Job Name, Run As, Directory, Command | ✓ | ✗ (tidak ada Directory) | ⚠️ |
| Tampilan command (Laravel queue/horizon) | ✓ | ✓ | ✅ |

**Perubahan kecil:** Tambah kolom "Directory" (`root_path` aplikasi) di tabel workers.

---

## 8. Settings

**Screenshot:** `08.manage.runcloud.io_..._settings.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Edit server name | ✓ | ✗ | ❌ |
| Provider info | ✓ (DigitalOcean, dll) | ✗ | ❌ Low priority |
| PHP-CLI Version selector | ✓ | ✗ | ❌ |
| Redis Password | ✓ | ✗ | ❌ |
| Auto Healing Services | ✓ | ✗ | ❌ |
| Default HTTPS Config | ✓ | ✗ | ❌ |
| Agent Settings (Sync Agent) | ✓ | ✗ | ❌ |
| **Restart Server** | ✓ | ✗ | ❌ Perlu |
| Update IP Address | ✓ | ✗ (auto dari sysinfo) | ⚠️ |
| Transfer Server | ✓ | ✗ | ❌ Low priority |
| Delete Server | ✓ | ✓ | ✅ |

**Perubahan yang diperlukan (short term):**
- Edit server name → `PATCH /servers/{server}` → update `servers.name`
- Restart server → dispatch AgentJob `shell` dengan payload `sudo reboot`
- Tampilkan info server (hostname, IP, OS) sebagai read-only dengan opsi override

---

## 9. Create Web App (Form)

**Screenshot:** `031.manage.runcloud.io_..._create.png`

RunCloud punya form create yang sangat detail dengan stack settings (NGINX/Apache), advanced PHP
settings (PM strategy, workers, timeouts, buffer sizes, dsb). Velink punya `applications/create.tsx`
yang sudah mencakup bagian inti. Belum ada advanced PHP/FPM settings.

---

## 10. App Detail Dashboard

**Screenshot:** `032.manage.runcloud.io_..._detilapp.png`

| Fitur | RunCloud | Velink (sekarang) | Status |
|---|---|---|---|
| Summary (domain, type, root, public path, mode, dir size, SSL, git) | ✓ | ✓ sebagian | ⚠️ |
| Traffic Stats chart (bandwidth/day) | ✓ | ✗ | ❌ Butuh parse nginx log |
| Sidebar: File Manager | ✓ | ✗ | ❌ Sangat kompleks |
| Sidebar: Monitoring per-app | ✓ | ✗ | ❌ |
| Sidebar: Domain Name management | ✓ | ✗ | ❌ |
| Sidebar: SSL/TLS | ✓ | ✗ (certbot sudah ada di provisioning) | ❌ Perlu UI |
| Sidebar: NGINX Config editor | ✓ | ✗ | ❌ |
| Sidebar: Firewall | ✓ | ✗ | ❌ |
| Sidebar: Web Server Log | ✓ | ✗ | ❌ |

---

## Prioritas Ringkasan

### 🔴 Tinggi (core UX, paling terasa bedanya)
1. **Monitoring page dedicated** — halaman baru dengan chart + gauge + time range
2. **Uptime di dashboard** — ganti card CPU dengan Uptime (agent + DB + UI)
3. **Settings page expansion** — edit name, restart server

### 🟡 Medium (feature parity penting)
4. **Web Applications list page per server** — dengan search + pagination + fix nav bug
5. **Databases + DB Users satu halaman** — gabungkan dengan tabs, tambah kolom Added On & Collation

### 🟢 Rendah (nice-to-have)
6. Search di cron, databases, workers
7. Workers: tambah kolom Directory
8. Services: per-service resource usage (CPU%, Memory)
9. App detail: Traffic Stats, SSL/TLS UI, NGINX Config editor
