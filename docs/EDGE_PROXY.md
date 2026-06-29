# Edge proxy (Caddy) — custom domains for private target servers

Velink serves apps in two modes, chosen **per server**:

- **Native** (default): the target VM is its own public edge — nginx + certbot
  on the target, DNS A record → the target's public IP. This is unchanged and
  needs nothing here.
- **Edge**: the target VM has no public IP and sits behind a shared **Caddy** on
  a public-IP VM. Caddy terminates TLS and reverse-proxies by Host to the right
  internal target. The panel pushes routes to Caddy automatically — you never
  SSH Caddy or the target's nginx.

This page covers the one-time Caddy setup for **edge** mode. Wildcard subdomains
are out of scope; this is for **custom domains**.

```
internet ─TLS─> Caddy (public IP) ── reverse_proxy ──> target nginx :80 (Host vhost)
                  │ Admin API (internal)                    ↑ Velink agent (auto)
                  │ on-demand TLS ── ask ──> panel
        VM panel (Velink) ── push route per app ──┘
```

## 1. Panel config

In the panel `.env`:

```
VELINK_EDGE_PROXY_DRIVER=caddy
VELINK_EDGE_PROXY_ADMIN_URL=http://<caddy-internal-ip>:2019
VELINK_EDGE_PROXY_ASK_SECRET=<a long random string>
VELINK_EDGE_PROXY_SERVER=edge
```

`driver=none` (the default) disables the feature entirely. With it set to
`caddy`, a per-server **"Serve this server behind the edge proxy"** toggle
appears under *Server → Settings*.

## 2. Install Caddy on the public edge VM (one time)

The base config below is loaded once; the panel manages routes after that via
the Admin API. Save as `/etc/caddy/caddy.json` (replace the IPs + secret):

```json
{
  "admin": { "listen": "<caddy-internal-ip>:2019" },
  "apps": {
    "http": {
      "servers": {
        "edge": {
          "listen": [":80", ":443"],
          "routes": []
        }
      }
    },
    "tls": {
      "automation": {
        "on_demand": {
          "permission": {
            "module": "http",
            "endpoint": "http://<panel-internal-ip>:8000/internal/caddy/authorize?key=<VELINK_EDGE_PROXY_ASK_SECRET>"
          }
        },
        "policies": [ { "on_demand": true } ]
      }
    }
  }
}
```

Run it:

```
caddy run --config /etc/caddy/caddy.json
```

(or a systemd unit with `ExecStart=/usr/bin/caddy run --config /etc/caddy/caddy.json`).

### Notes
- **Admin API must bind an internal IP**, never a public one. The panel reaches
  it over the private network on port 2019.
- **`on_demand` + the `ask` permission is mandatory.** Caddy calls the panel's
  `internal/caddy/authorize` endpoint before issuing a certificate; the panel
  authorizes only domains that belong to an edge-backed server. Without it, a
  stranger pointing DNS at the edge could exhaust Let's Encrypt rate limits.
  Caddy appends `&domain=<host>` to the endpoint URL; the static `key` query
  must match `VELINK_EDGE_PROXY_ASK_SECRET`.
- Caddy issues certs over **HTTP-01** (it is the public edge on :80/:443) — no
  DNS API needed for custom domains.
- For production ACME, add an issuer with your email under
  `tls.automation.policies[0].issuers` (optional).

## 3. Per-app workflow (all automatic)

1. *Server → Settings*: enable **edge proxy** for the (private) target server.
2. Create an app with a custom domain as usual. The panel renders the target's
   HTTP-only nginx vhost (via the agent) **and** pushes a reverse-proxy route to
   Caddy keyed by the app UUID (`velink-app-<uuid>`).
3. Point the domain's DNS (A/CNAME) at the **edge VM's public IP**. This is the
   only manual step, and it's on the domain owner's DNS.
4. First HTTPS request → Caddy asks the panel → cert is issued → traffic
   reverse-proxies to the target's internal IP on port 80.

Changing the domain re-points the route; deleting the app removes it. Per-app
certbot (`Enable SSL`) is disabled for edge-backed servers — TLS lives on Caddy.

## 4. Target nginx

No action needed. The target keeps Velink's default HTTP-only vhost on :80
(vhost selected by Host header), reachable from Caddy over the private network.
