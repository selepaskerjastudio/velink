# Gateway

Realtime gateway (Go) yang berjalan di VM panel, berdampingan dengan `/panel`.

Tanggung jawab:
- Menerima koneksi WSS dial-out dari setiap `/agent` di server terkelola (`wss://.../agent/connect`), memverifikasi token per-server.
- Menjaga koneksi persisten + presence (online/offline) di Redis.
- Menjembatani pesan antara agent dan `/panel` lewat Redis pub/sub: Job dari panel diteruskan ke agent yang tepat, output/progress dari agent diteruskan balik ke panel untuk disimpan & di-broadcast ke browser via Laravel Reverb.
- (Fase 5) Multiplex channel PTY untuk web terminal.

## Status

Skeleton Fase 0 **sudah jalan**: endpoint WSS + autentikasi token + presence Redis + bridge pub/sub. Semantik Job sesungguhnya (eksekusi, state machine, stream output) menyusul di Fase 1 bersama `/agent`. Lihat `docs/TODO.md` & `docs/PLAN.md`.

## Struktur

```
cmd/gateway/main.go          # entrypoint: wiring + graceful shutdown
internal/config              # load konfigurasi dari env
internal/protocol            # envelope & konstanta channel (dipakai bareng panel/agent)
internal/auth                # verifikasi token agent ke panel (token di-hash di DB)
internal/presence            # tulis presence + TTL di Redis, publish transisi
internal/hub                 # registry koneksi agent (server_id -> conn), routing
internal/bridge              # subscribe dispatch (panel->agent), publish inbound (agent->panel)
internal/server              # HTTP/WS server: /agent/connect, /healthz
```

## Alur autentikasi

Agent dial-out membawa header `Authorization: Bearer <token>`, `X-Server-Id: <id>`,
`X-Agent-Version: <v>`. Gateway memanggil panel `POST /internal/agent/verify`
(dilindungi shared secret `X-Gateway-Secret`) — karena `agent_token` disimpan
ter-hash (bcrypt) di DB panel, verifikasi harus di panel, gateway tetap stateless.

## Redis channels

- `coruncloud:gateway:dispatch` — panel → agent (envelope dengan `server_id`).
- `coruncloud:gateway:inbound`  — agent → panel (heartbeat di-handle lokal, sisanya diteruskan).
- `coruncloud:gateway:presence` — transisi online/offline.
- `coruncloud:presence:server:<id>` — key presence per-server (dengan TTL heartbeat).

## Menjalankan (dev)

```bash
cp .env.example .env        # set GATEWAY_PANEL_SECRET sama dengan GATEWAY_SECRET di panel/.env
set -a && . ./.env && set +a
go run ./cmd/gateway
```

Health check: `curl http://127.0.0.1:8080/healthz` → `{"status":"ok","agents":N}`.

## Build & test

```bash
go build ./...
go vet ./...
go test ./...
```
