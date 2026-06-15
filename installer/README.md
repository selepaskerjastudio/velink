# Installer

Script instalasi `/agent` di server target — satu-satunya langkah manual dalam seluruh alur.

Rencana:
- Script `agent.sh` yang bisa dijalankan via one-liner:
  ```
  curl -fsSL https://<panel-url>/install/agent.sh | sudo bash -s -- --token=<TOKEN> --panel=<PANEL_URL> --server-id=<ID>
  ```
- Tugas script: deteksi arsitektur/OS, download binary `/agent` yang sesuai, pasang sebagai systemd service, tulis konfigurasi (token, alamat panel/gateway), start service.
- Token & perintah ini di-generate dari halaman "Add Server" di panel (lihat `panel/app/Http/Controllers/ServerController.php`) — token ditampilkan hanya sekali saat server dibuat.

Status: belum diimplementasikan (🔴 Fase 1, lihat `docs/TODO.md` dan `docs/PLAN.md`).
