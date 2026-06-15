# Agent

Binary tunggal (Go) yang diinstall di setiap server/VM yang dikelola.

Tanggung jawab:
- Satu-satunya komponen yang diinstall manual (lewat installer one-liner di `/installer`). Berjalan sebagai systemd service dengan hak privileged.
- Dial-out WSS ke `/gateway` di VM panel menggunakan token per-server, kirim heartbeat & metrics resource (`gopsutil`).
- Eksekutor Job: menjalankan provisioning & operasi sehari-hari secara idempotent, antara lain:
  - Install & konfigurasi nginx, PHP-FPM (7.4 & 8.1–8.4 via `ondrej/php`), composer, node, supervisord, certbot.
  - Install & kelola database (MySQL/MariaDB, PostgreSQL, MongoDB) dan Redis.
  - Render & reload config (vhost nginx, fpm pool, supervisor program, cron drop-in) dari template.
  - Deploy aplikasi: mode in-place (`update biasa`) maupun zero-downtime (releases + symlink).
  - Kontrol systemd/supervisord/cron, stream log/output balik ke panel.
- (Fase 5) Membuka PTY (`creack/pty`) untuk web terminal.

Status: belum diimplementasikan (🔴 Fase 1, lihat `docs/TODO.md` dan `docs/PLAN.md`).
