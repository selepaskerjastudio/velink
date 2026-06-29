#!/usr/bin/env bash
#
# Velink panel installer — bootstraps a fresh Ubuntu VM into a working panel
# (control plane: Laravel panel + Go gateway + nginx + PostgreSQL + Redis).
#
# Quick start (interactive wizard):
#   curl -fsSL https://raw.githubusercontent.com/<owner>/velink/main/installer/panel-install.sh | sudo bash
#
# Or non-interactive:
#   sudo bash panel-install.sh --non-interactive \
#       --domain panel.example.com --email you@example.com
#
# Flags (all optional; missing required values are asked for in the wizard):
#   --domain=         public panel domain, e.g. panel.example.com (must point at this VM)
#   --email=          email for Let's Encrypt / certbot
#   --repo=           git repo URL          (default: the official Velink repo)
#   --branch=         git branch            (default: main)
#   --dir=            install directory      (default: /opt/velink)
#   --db-pass=        PostgreSQL password    (default: random)
#   --php=            PHP version            (default: 8.3)
#   --go-version=     Go toolchain version   (default: 1.24.5)
#   --no-ssl          skip certbot (HTTP only — agents need TLS, so add SSL later)
#   --non-interactive fail instead of prompting for missing required values
#
set -euo pipefail

# ─── Colour helpers ────────────────────────────────────────────────────────────
if [ -t 1 ] && command -v tput >/dev/null 2>&1; then
    BOLD="$(tput bold)"; RED="$(tput setaf 1)"; GREEN="$(tput setaf 2)"
    YELLOW="$(tput setaf 3)"; CYAN="$(tput setaf 6)"; RESET="$(tput sgr0)"
else
    BOLD="" RED="" GREEN="" YELLOW="" CYAN="" RESET=""
fi

STEP=0
TOTAL=12
LOG="/var/log/velink-panel-install.log"
: > "$LOG" 2>/dev/null || LOG="$(mktemp)"

step() { STEP=$((STEP + 1)); echo ""; echo "${BOLD}[${STEP}/${TOTAL}]${RESET} ${CYAN}▶ ${*}${RESET}"; }
ok()   { echo "      ${GREEN}✓${RESET}  ${*}"; }
info() { echo "      ${YELLOW}→${RESET}  ${*}"; }
die()  { echo ""; echo "${RED}✗  ERROR: ${*}${RESET}" >&2; echo "${RED}   Full log: ${LOG}${RESET}" >&2; exit 1; }

# Run a command quietly; dump its log and abort on failure.
run() {
    local msg="$1"; shift
    if "$@" >>"$LOG" 2>&1; then ok "$msg"; else
        echo "${RED}✗ ${msg}${RESET}"; tail -n 30 "$LOG" >&2; die "$msg"
    fi
}

# Prompt the user (reads from the terminal even when the script is piped).
ask() {
    local __var="$1" prompt="$2" default="${3:-}" secret="${4:-}" reply
    if [ "$NON_INTERACTIVE" = "1" ]; then
        [ -n "$default" ] || die "missing required value for ${__var} (--$(echo "$__var" | tr 'A-Z_' 'a-z-'))"
        printf -v "$__var" '%s' "$default"; return
    fi
    local p="  ${BOLD}${prompt}${RESET}"
    [ -n "$default" ] && p="$p ${YELLOW}[${default}]${RESET}"
    if [ "$secret" = "secret" ]; then
        read -rsp "$p: " reply < /dev/tty; echo ""
    else
        read -rp "$p: " reply < /dev/tty
    fi
    [ -z "$reply" ] && reply="$default"
    printf -v "$__var" '%s' "$reply"
}

# Replace (or append) a KEY=value line in a dotenv / EnvironmentFile.
set_env() {
    local key="$1" val="$2" file="$3"
    sed -i "/^[#[:space:]]*${key}=/d" "$file"
    printf '%s=%s\n' "$key" "$val" >> "$file"
}

rand_hex() { openssl rand -hex "${1:-16}"; }

# ─── Defaults & flag parsing ────────────────────────────────────────────────────
DOMAIN=""
EMAIL=""
REPO="https://github.com/selepaskerjastudio/velink.git"
BRANCH="main"
INSTALL_DIR="/opt/velink"
DB_PASS=""
PHP_VER="8.3"
GO_VERSION="1.24.5"
USE_SSL="1"
NON_INTERACTIVE="0"

for arg in "$@"; do
    case "$arg" in
        --domain=*)       DOMAIN="${arg#*=}" ;;
        --email=*)        EMAIL="${arg#*=}" ;;
        --repo=*)         REPO="${arg#*=}" ;;
        --branch=*)       BRANCH="${arg#*=}" ;;
        --dir=*)          INSTALL_DIR="${arg#*=}" ;;
        --db-pass=*)      DB_PASS="${arg#*=}" ;;
        --php=*)          PHP_VER="${arg#*=}" ;;
        --go-version=*)   GO_VERSION="${arg#*=}" ;;
        --no-ssl)         USE_SSL="0" ;;
        --non-interactive) NON_INTERACTIVE="1" ;;
        *) die "unknown argument: $arg" ;;
    esac
done

# ─── Banner ──────────────────────────────────────────────────────────────────────
clear 2>/dev/null || true
echo ""
echo "${BOLD}  Velink Panel Installer${RESET}"
echo "  ──────────────────────────────────────────────────"
echo "  Bootstraps this VM into a Velink control panel:"
echo "    • PHP-FPM ${PHP_VER} + Composer + Node 20 + Go ${GO_VERSION}"
echo "    • PostgreSQL + Redis + nginx (+ certbot SSL)"
echo "    • Laravel panel, Go gateway, 4 systemd services"
echo "    • Pre-built agent binaries (so you can add servers immediately)"
echo "  ──────────────────────────────────────────────────"

# ─── Step 1: Prerequisites ───────────────────────────────────────────────────────
step "Checking prerequisites"
[ "$(id -u)" -eq 0 ] || die "must run as root (use sudo)"
ok "Running as root"
command -v apt-get >/dev/null 2>&1 || die "this installer targets Ubuntu (apt-get not found)"
ok "Debian/Ubuntu detected"
if [ -f /etc/os-release ]; then ok "$(. /etc/os-release && echo "${PRETTY_NAME:-unknown}")"; fi
case "$(uname -m)" in
    x86_64|amd64)  GOARCH="amd64" ;;
    aarch64|arm64) GOARCH="arm64" ;;
    *) die "unsupported architecture: $(uname -m)" ;;
esac
ok "Architecture: ${GOARCH}"
[ "$NON_INTERACTIVE" = "1" ] || [ -e /dev/tty ] || die "no terminal for the wizard; re-run with --non-interactive and flags"

# ─── Step 2: Configuration wizard ────────────────────────────────────────────────
step "Configuration"
ask DOMAIN      "Panel domain (DNS A record must already point here)" "$DOMAIN"
[ -n "$DOMAIN" ] || die "domain is required"
if [ "$USE_SSL" = "1" ]; then
    ask EMAIL   "Email for Let's Encrypt SSL" "$EMAIL"
    [ -n "$EMAIL" ] || die "email is required for SSL (or pass --no-ssl)"
fi
ask REPO        "Git repository URL" "$REPO"
ask BRANCH      "Git branch" "$BRANCH"
ask INSTALL_DIR "Install directory" "$INSTALL_DIR"
[ -n "$DB_PASS" ] || DB_PASS="$(rand_hex 16)"

echo ""
echo "  ${BOLD}Summary${RESET}"
echo "    Domain      : ${DOMAIN}"
echo "    SSL         : $([ "$USE_SSL" = 1 ] && echo "Let's Encrypt (${EMAIL})" || echo "disabled (HTTP only)")"
echo "    Repo/branch : ${REPO} @ ${BRANCH}"
echo "    Install dir : ${INSTALL_DIR}"
echo "    Database    : PostgreSQL  db=velink user=velink (password generated)"
echo "    Ports       : Reverb 8080, Gateway 8081 (both proxied via nginx 443)"
echo ""
if [ "$NON_INTERACTIVE" != "1" ]; then
    ask CONFIRM "Proceed with installation? (yes/no)" "yes"
    case "$CONFIRM" in y|yes|Y|YES) ;; *) die "aborted by user" ;; esac
fi

export DEBIAN_FRONTEND=noninteractive
PHP_BIN="/usr/bin/php${PHP_VER}"
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"

# ─── Step 3: System packages ─────────────────────────────────────────────────────
step "Installing system packages (this takes a few minutes)"
run "apt update"                 apt-get update -y
run "Base tooling"               apt-get install -y software-properties-common ca-certificates curl gnupg lsb-release unzip git acl openssl
run "Add ondrej/php PPA"         add-apt-repository -y ppa:ondrej/php
run "apt update (php)"           apt-get update -y
run "PHP ${PHP_VER} + extensions" apt-get install -y \
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-pgsql" "php${PHP_VER}-redis" \
    "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" "php${PHP_VER}-curl" "php${PHP_VER}-zip" \
    "php${PHP_VER}-bcmath" "php${PHP_VER}-gd" "php${PHP_VER}-intl"
run "PostgreSQL"                 apt-get install -y postgresql
run "Redis"                      apt-get install -y redis-server
run "nginx"                      apt-get install -y nginx
if [ "$USE_SSL" = "1" ]; then run "certbot" apt-get install -y certbot python3-certbot-nginx; fi

# Node 20 (NodeSource)
if ! command -v node >/dev/null 2>&1 || [ "$(node -v 2>/dev/null | cut -c2 | tr -d .)" -lt 2 ] 2>/dev/null; then
    run "NodeSource repo" bash -c 'curl -fsSL https://deb.nodesource.com/setup_20.x | bash -'
    run "Node.js 20" apt-get install -y nodejs
else ok "Node.js present ($(node -v))"; fi

# Composer
if ! command -v composer >/dev/null 2>&1; then
    run "Composer" bash -c 'curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer'
else ok "Composer present"; fi

# Go toolchain
if ! /usr/local/go/bin/go version 2>/dev/null | grep -q "go${GO_VERSION}"; then
    run "Download Go ${GO_VERSION}" curl -fsSL -o /tmp/go.tgz "https://go.dev/dl/go${GO_VERSION}.linux-${GOARCH}.tar.gz"
    run "Install Go" bash -c 'rm -rf /usr/local/go && tar -C /usr/local -xzf /tmp/go.tgz && rm -f /tmp/go.tgz'
else ok "Go ${GO_VERSION} present"; fi
export PATH="/usr/local/go/bin:$PATH"
GO_BIN="/usr/local/go/bin/go"

run "Enable Redis"   systemctl enable --now redis-server
run "Enable PostgreSQL" systemctl enable --now postgresql

# ─── Step 4: PostgreSQL database ─────────────────────────────────────────────────
step "Creating PostgreSQL database & user"
sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='velink'" | grep -q 1 \
    && info "role 'velink' already exists — updating password" \
    || run "Create role velink" sudo -u postgres psql -c "CREATE ROLE velink LOGIN PASSWORD '${DB_PASS}';"
sudo -u postgres psql -c "ALTER ROLE velink LOGIN PASSWORD '${DB_PASS}';" >>"$LOG" 2>&1
sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='velink'" | grep -q 1 \
    && info "database 'velink' already exists" \
    || run "Create database velink" sudo -u postgres psql -c "CREATE DATABASE velink OWNER velink;"
ok "PostgreSQL ready"

# ─── Step 5: Clone repository ────────────────────────────────────────────────────
step "Fetching Velink source"
if [ -d "$INSTALL_DIR/.git" ]; then
    info "repo exists at ${INSTALL_DIR} — pulling latest"
    run "git fetch" git -C "$INSTALL_DIR" fetch --all --prune
    run "git checkout ${BRANCH}" git -C "$INSTALL_DIR" checkout "$BRANCH"
    run "git pull" git -C "$INSTALL_DIR" pull --ff-only
else
    mkdir -p "$(dirname "$INSTALL_DIR")"
    run "Clone ${REPO}" git clone --branch "$BRANCH" "$REPO" "$INSTALL_DIR"
fi

# ─── Step 6: Panel (Laravel) ─────────────────────────────────────────────────────
step "Configuring the Laravel panel"
cd "$INSTALL_DIR/panel"
[ -f .env ] || cp .env.example .env

run "Composer install" composer install --no-dev --optimize-autoloader --no-interaction
"$PHP_BIN" artisan key:generate --force >>"$LOG" 2>&1 && ok "APP_KEY generated"

REVERB_APP_ID="$(rand_hex 8)"
REVERB_APP_KEY="$(rand_hex 16)"
REVERB_APP_SECRET="$(rand_hex 16)"
GATEWAY_SECRET="$(rand_hex 32)"

set_env APP_NAME              "Velink" .env
set_env APP_ENV               "production" .env
set_env APP_DEBUG             "false" .env
set_env APP_URL               "https://${DOMAIN}" .env
set_env DB_CONNECTION         "pgsql" .env
set_env DB_HOST               "127.0.0.1" .env
set_env DB_PORT               "5432" .env
set_env DB_DATABASE           "velink" .env
set_env DB_USERNAME           "velink" .env
set_env DB_PASSWORD           "${DB_PASS}" .env
set_env SESSION_DRIVER        "database" .env
set_env QUEUE_CONNECTION      "database" .env
set_env CACHE_STORE           "database" .env
set_env BROADCAST_CONNECTION  "reverb" .env
set_env REDIS_HOST            "127.0.0.1" .env
set_env REDIS_PORT            "6379" .env
# Reverb: server binds 8080 locally; clients connect via nginx on 443/wss.
set_env REVERB_APP_ID         "${REVERB_APP_ID}" .env
set_env REVERB_APP_KEY        "${REVERB_APP_KEY}" .env
set_env REVERB_APP_SECRET     "${REVERB_APP_SECRET}" .env
set_env REVERB_HOST           "${DOMAIN}" .env
set_env REVERB_PORT           "443" .env
set_env REVERB_SCHEME         "https" .env
set_env REVERB_SERVER_HOST    "0.0.0.0" .env
set_env REVERB_SERVER_PORT    "8080" .env
# Gateway: agents reach it at wss://domain/agent/connect (nginx → 127.0.0.1:8081).
set_env GATEWAY_SECRET        "${GATEWAY_SECRET}" .env
set_env GATEWAY_PUBLIC_URL    "wss://${DOMAIN}" .env

run "npm ci"        npm ci
run "Vite build"    npm run build
run "Migrate DB"    "$PHP_BIN" artisan migrate --force
run "storage:link"  "$PHP_BIN" artisan storage:link
run "Config cache"  "$PHP_BIN" artisan config:cache
run "Route cache"   "$PHP_BIN" artisan route:cache
run "View cache"    "$PHP_BIN" artisan view:cache

# ─── Step 7: Gateway (Go) ────────────────────────────────────────────────────────
step "Building the Go gateway"
cd "$INSTALL_DIR/gateway"
[ -f .env ] || cp .env.example .env
set_env GATEWAY_LISTEN        ":8081" .env
set_env GATEWAY_PANEL_URL     "http://127.0.0.1:80" .env
set_env GATEWAY_PANEL_SECRET  "${GATEWAY_SECRET}" .env
set_env GATEWAY_REDIS_ADDR    "127.0.0.1:6379" .env
set_env GATEWAY_REDIS_PASSWORD "" .env
set_env GATEWAY_REDIS_DB      "0" .env
set_env GATEWAY_PRESENCE_TTL  "90" .env
run "Build gateway binary" "$GO_BIN" build -o /usr/local/bin/velink-gateway ./cmd/gateway

# ─── Step 8: Pre-build agent binaries ────────────────────────────────────────────
step "Building agent binaries (linux amd64 + arm64)"
cd "$INSTALL_DIR/agent"
mkdir -p "$INSTALL_DIR/panel/storage/app/agent-bins"
for a in amd64 arm64; do
    run "agent-linux-${a}-latest" env GOOS=linux GOARCH="$a" CGO_ENABLED=0 \
        "$GO_BIN" build -o "$INSTALL_DIR/panel/storage/app/agent-bins/agent-linux-${a}-latest" ./cmd/agent
done

# ─── Step 9: nginx + php-fpm ─────────────────────────────────────────────────────
step "Configuring nginx + php-fpm"
VHOST="/etc/nginx/sites-available/velink"
cat > "$VHOST" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${INSTALL_DIR}/panel/public;
    index index.php;
    charset utf-8;
    client_max_body_size 100M;

    # Reverb browser WebSocket. The trailing slash is REQUIRED — without it the
    # prefix also captures /apps/ and /applications/ and breaks PHP routing.
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 86400;
    }

    # Gateway WebSocket — agents dial in here.
    location /agent/connect {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 86400;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
EOF
ln -sf "$VHOST" /etc/nginx/sites-enabled/velink
rm -f /etc/nginx/sites-enabled/default
run "nginx config test" nginx -t
run "Enable php-fpm" systemctl enable --now "php${PHP_VER}-fpm"
run "Reload nginx"   systemctl reload nginx

# ─── Step 10: systemd services ───────────────────────────────────────────────────
step "Installing systemd services"
write_unit() { cat > "/etc/systemd/system/$1"; ok "$1"; }

write_unit velink-queue.service <<EOF
[Unit]
Description=Velink queue worker
After=network.target postgresql.service redis-server.service
[Service]
User=www-data
WorkingDirectory=${INSTALL_DIR}/panel
ExecStart=${PHP_BIN} artisan queue:work --tries=3 --sleep=3
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

write_unit velink-reverb.service <<EOF
[Unit]
Description=Velink Reverb (browser WebSocket)
After=network.target redis-server.service
[Service]
User=www-data
WorkingDirectory=${INSTALL_DIR}/panel
ExecStart=${PHP_BIN} artisan reverb:start --host=0.0.0.0 --port=8080
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

write_unit velink-agent-listen.service <<EOF
[Unit]
Description=Velink agent listener (gateway inbound processor)
After=network.target redis-server.service
[Service]
User=www-data
WorkingDirectory=${INSTALL_DIR}/panel
ExecStart=${PHP_BIN} artisan agent:listen
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

write_unit velink-gateway.service <<EOF
[Unit]
Description=Velink Gateway (agent WebSocket bridge)
After=network.target redis-server.service
[Service]
User=www-data
EnvironmentFile=${INSTALL_DIR}/gateway/.env
ExecStart=/usr/local/bin/velink-gateway
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

# Ownership: php-fpm and all services run as www-data, so it must own the tree.
run "Set ownership" chown -R www-data:www-data "$INSTALL_DIR"
chmod -R ug+rwX "$INSTALL_DIR/panel/storage" "$INSTALL_DIR/panel/bootstrap/cache"

run "daemon-reload" systemctl daemon-reload
run "Enable services" systemctl enable --now \
    velink-queue velink-reverb velink-agent-listen velink-gateway

# ─── Step 11: SSL ────────────────────────────────────────────────────────────────
step "TLS certificate"
if [ "$USE_SSL" = "1" ]; then
    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect >>"$LOG" 2>&1; then
        ok "SSL issued & nginx switched to HTTPS"
    else
        info "certbot failed (see ${LOG}). Panel is up on HTTP."
        info "Fix DNS, then run: certbot --nginx -d ${DOMAIN} --redirect -m ${EMAIL} --agree-tos"
    fi
else
    info "SSL skipped (--no-ssl). Agents require wss:// — issue a cert before adding servers:"
    info "  certbot --nginx -d ${DOMAIN} --redirect -m you@example.com --agree-tos"
fi

# ─── Step 12: Done ───────────────────────────────────────────────────────────────
step "Finalising"
sleep 2
SCHEME="$([ "$USE_SSL" = 1 ] && echo https || echo http)"
echo ""
echo "${BOLD}${GREEN}  ══════════════════════════════════════════════════${RESET}"
echo "${BOLD}${GREEN}  ✓  Velink panel installed${RESET}"
echo "${BOLD}${GREEN}  ══════════════════════════════════════════════════${RESET}"
echo ""
echo "  Create the first admin account:"
echo "    ${BOLD}${SCHEME}://${DOMAIN}/register${RESET}"
echo ""
echo "  Credentials & secrets are in: ${INSTALL_DIR}/panel/.env"
echo "    DB password: ${DB_PASS}"
echo ""
echo "  Service status:"
echo "    systemctl status velink-gateway velink-reverb velink-queue velink-agent-listen"
echo "    journalctl -u velink-gateway -f"
echo ""
echo "  Redeploy later with: ${INSTALL_DIR}/deploy.sh   (add --gateway to rebuild the gateway)"
echo ""
