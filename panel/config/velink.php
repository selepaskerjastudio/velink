<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shared web-application Linux user
    |--------------------------------------------------------------------------
    |
    | Every managed web application runs under a single shared OS user
    | (RunCloud-style). Each app lives in /home/{user}/webapps/{app_slug} and
    | gets its own php-fpm pool + socket keyed by app_slug, so apps stay
    | isolated at the pool level while sharing the OS account.
    |
    */

    'webapp_user' => env('VELINK_WEBAPP_USER', 'velink'),

    /*
    |--------------------------------------------------------------------------
    | Edge proxy (Caddy) — optional, per-server
    |--------------------------------------------------------------------------
    |
    | Some target servers have no public IP and sit behind a shared Caddy on a
    | public-IP VM (Coolify-style). For those servers (flagged per-server via
    | `servers.uses_edge_proxy`) the panel pushes a reverse-proxy route to
    | Caddy's Admin API on app create/domain-change/delete, and Caddy gates
    | on-demand TLS through the panel's `internal/caddy/authorize` endpoint.
    |
    | Native servers (their own public IP, certbot on the target) are
    | unaffected. `driver = none` (default) disables the feature entirely.
    |
    */

    'edge_proxy' => [
        'driver' => env('VELINK_EDGE_PROXY_DRIVER', 'none'), // none | caddy
        'admin_url' => env('VELINK_EDGE_PROXY_ADMIN_URL'),
        'ask_secret' => env('VELINK_EDGE_PROXY_ASK_SECRET'),
        'server' => env('VELINK_EDGE_PROXY_SERVER', 'edge'), // Caddy http server name
    ],

];
