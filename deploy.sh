#!/bin/bash
set -e

# ── Colors ────────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
RESET='\033[0m'

# ── ASCII banner ───────────────────────────────────────────────────────────────
echo -e "${BOLD}${CYAN}"
cat << 'EOF'
 __   __ _____  _      ___  _   _ _  __
 \ \ / /| ____|| |    |_ _|| \ | || |/ /
  \ V / |  _|  | |     | | |  \| || ' /
   | |  | |___ | |___  | | | |\  || . \
   |_|  |_____||_____|___||_| \_||_|\_\
EOF
echo -e "${RESET}"

# ── Helpers ────────────────────────────────────────────────────────────────────
STEP=0

step() {
    STEP=$((STEP + 1))
    echo -e "\n${BOLD}[${STEP}] $1${RESET}"
}

ok() {
    echo -e "    ${GREEN}✓ $1${RESET}"
}

info() {
    echo -e "    ${DIM}$1${RESET}"
}

# ── Args ───────────────────────────────────────────────────────────────────────
RESTART_GATEWAY=false
for arg in "$@"; do
    case $arg in
        --gateway) RESTART_GATEWAY=true ;;
    esac
done

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── Steps ──────────────────────────────────────────────────────────────────────
step "Pulling latest code"
cd "$REPO_DIR"
git pull
ok "Repository up to date"

step "Installing PHP dependencies"
cd "$REPO_DIR/panel"
composer install --no-dev --optimize-autoloader --quiet
ok "Composer packages installed"

step "Building frontend assets"
npm ci --silent
npm run build --silent
ok "Vite build complete"

step "Running database migrations"
php artisan migrate --force
ok "Migrations done"

step "Caching config / routes / views"
php artisan config:cache  && info "Config cached"
php artisan route:cache   && info "Routes cached"
php artisan view:cache    && info "Views cached"
ok "All caches refreshed"

step "Restarting panel services"
systemctl restart velink-queue       && info "velink-queue restarted"
systemctl restart velink-reverb      && info "velink-reverb restarted"
systemctl restart velink-agent-listen && info "velink-agent-listen restarted"
ok "Panel services running"

if [ "$RESTART_GATEWAY" = true ]; then
    step "Rebuilding and restarting gateway"
    cd "$REPO_DIR/gateway"
    go build -o /usr/local/bin/velink-gateway ./cmd/gateway
    info "Binary built"
    systemctl restart velink-gateway
    ok "Gateway restarted"
fi

echo -e "\n${GREEN}${BOLD}Deploy complete.${RESET}\n"
