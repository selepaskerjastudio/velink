# Provision

Template konfigurasi & referensi script provisioning yang dipakai/dirender oleh `/agent` di server terkelola.

Isi (rencana):
- Template vhost **nginx** per-aplikasi (termasuk binding ke socket PHP-FPM sesuai `php_version` aplikasi).
- Template **PHP-FPM pool** per-aplikasi/per-versi (7.4, 8.1–8.4).
- Template program **supervisord** untuk queue worker (`artisan queue:work` / Horizon).
- Drop-in **cron** (`/etc/cron.d/...`) per-job.
- Referensi langkah instalasi paket per OS (Ubuntu LTS): `ondrej/php` PPA, PGDG (PostgreSQL), repo resmi MongoDB, MySQL/MariaDB, Redis.
- Template **deploy script** default untuk mode in-place & zero-downtime (lihat `docs/PLAN.md` Fase 3).

Template-template ini di-bundle/embed ke dalam `/agent` (atau diambil dari panel sebagai bagian payload Job) — agent yang merender & mengeksekusinya di server target.

Status: belum diimplementasikan (🔴 Fase 1–3, lihat `docs/TODO.md` dan `docs/PLAN.md`).
