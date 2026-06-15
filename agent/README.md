# Agent

Binary tunggal (Go) yang diinstall di setiap server/VM yang dikelola.

Tanggung jawab:
- Satu-satunya komponen yang diinstall manual (lewat installer one-liner di `/installer`). Berjalan sebagai systemd service dengan hak privileged.
- Dial-out WSS ke `/gateway` di VM panel menggunakan token per-server, kirim heartbeat & (Fase 5) metrics resource.
- Eksekutor Job: menjalankan provisioning & operasi sehari-hari secara idempotent.

## Status

Fase 1 **sudah jalan**: transport dial-out + reconnect (backoff), heartbeat, dan executor Job (`shell`, `write_file`, `render_config`) yang men-stream output + exit code. Build/vet/test bersih. Provisioning multi-PHP/DB dikirim panel sebagai serangkaian job `shell` idempotent (lihat `panel/app/Provisioning`).

## Struktur

```
cmd/agent/main.go            # entrypoint: load config + run client
internal/config              # load AGENT_* dari env (EnvironmentFile systemd)
internal/protocol            # mirror envelope gateway (lihat catatan di file)
internal/executor            # eksekusi job: shell / write_file / render_config (+ test)
internal/client              # dial-out WSS, hello, heartbeat, reconnect, read/write pump
```

## Konfigurasi

`cp .env.example .env` lalu isi `AGENT_GATEWAY_URL`, `AGENT_TOKEN`, `AGENT_SERVER_ID`
(installer menulis ini ke `/etc/coruncloud/agent.env`).

## Build & test

```bash
go build ./...
go vet ./...
go test ./...
```
