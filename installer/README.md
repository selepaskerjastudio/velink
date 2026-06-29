# Installer

Dua script bootstrap, dijalankan via one-liner `curl | sudo bash`.

## `panel-install.sh` — setup panel di VM kontrol

Bootstrap VM Ubuntu kosong jadi panel lengkap (Laravel panel + Go gateway + nginx + PostgreSQL + Redis + certbot). Wizard interaktif:

```bash
curl -fsSL https://raw.githubusercontent.com/selepaskerjastudio/velink/main/installer/panel-install.sh | sudo bash
```

Non-interaktif:

```bash
sudo bash panel-install.sh --non-interactive --domain panel.example.com --email you@example.com
```

Yang dilakukan: install PHP-FPM + Composer + Node 20 + Go + PostgreSQL + Redis + nginx (+certbot), clone repo, generate `.env` panel & gateway (secret acak, `GATEWAY_SECRET` match dua sisi), composer/npm/build/migrate, build gateway, pre-build binary agent (linux amd64/arm64) ke `storage/app/agent-bins`, pasang 4 systemd unit (`velink-queue`, `velink-reverb`, `velink-agent-listen`, `velink-gateway`), tulis vhost nginx (`/app/` → Reverb 8080, `/agent/connect` → gateway 8081), dan terbitkan SSL. Log: `/var/log/velink-panel-install.log`.

Flag: `--domain --email --repo --branch --dir --db-pass --php --go-version --no-ssl --non-interactive`.

> Prasyarat: DNS A record domain sudah mengarah ke VM (untuk certbot).

## `agent.sh` — install agent di server terkelola

One-liner, di-generate otomatis oleh panel di halaman "Add Server":

```bash
curl -fsSL https://<panel-url>/install/agent.sh | sudo bash -s -- --token=<TOKEN> --server-id=<ID>
```

Tugas: deteksi OS/arsitektur, download binary `velink-agent` ke `/usr/local/bin/`, tulis config (`/etc/velink/agent.env`, chmod 600), pasang systemd unit `velink-agent`, arm provisioning ke panel, start service. Token ditampilkan sekali saat server dibuat (lihat `panel/app/Http/Controllers/ServerController.php`).
